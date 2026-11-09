<?php

namespace App\Platform\Meta;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Services\AccountService;
use App\Platform\Meta\Events\MetaAppChanged;
use App\Platform\Meta\Exceptions\MetaException;
use App\Platform\Meta\Models\MetaApp;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Partners' own Meta apps (SC-1). Which app a client's page connects
 * through: the partner's own when it is active, else the house partner's
 * where the partner lets its clients use it. A change of the id or secret
 * is checked with Meta first, then waits for a second partner owner (with
 * the password again) or, with no second owner, for the single-approver
 * wait; the approved values keep working until then. Every step is audited.
 */
class MetaApps
{
    public function __construct(
        private Graph $graph,
        private AccountService $identity,
        private Recipients $recipients,
        private AuditLogger $audit,
    ) {}

    public function of(Partner $partner): ?MetaApp
    {
        return MetaApp::query()->where('partner_id', $partner->getKey())->first();
    }

    public function find(string $id): ?MetaApp
    {
        return MetaApp::query()->whereKey($id)->first();
    }

    /** The app a client of this partner connects through, or null (none set up). */
    public function appFor(Partner $partner, bool $houseAllowed): ?MetaApp
    {
        $own = $this->of($partner);
        if ($own?->isActive()) {
            return $own;
        }
        if (! $houseAllowed && ! $partner->is_house) {
            return null;
        }
        $house = $partner->is_house ? null : MetaApp::query()->whereIn('partner_id', Partner::query()->where('is_house', true)->select('id'))->first();

        return $house?->isActive() ? $house : null;
    }

    /** Asks for a new app id and secret (checked with Meta); applies once approved. */
    public function change(Partner $partner, User $actor, string $password, ?int $baseVersion, string $appId, string $secret): MetaApp
    {
        $this->identity->assertPassword($actor, $password);
        $name = $this->graph->appName($appId, $secret);
        $alone = $this->approvers($partner, $actor)->isEmpty();
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($partner, $actor, $baseVersion, $appId, $secret, $name, $alone, $now) {
            $app = MetaApp::query()->where('partner_id', $partner->getKey())->lockForUpdate()->first();
            if ($app !== null && $app->version !== $baseVersion) {
                throw MetaException::versionConflict();
            }
            $app ??= tap(new MetaApp, function (MetaApp $new) use ($partner) {
                $new->partner_id = $partner->getKey();
                $new->status = MetaApp::PENDING;
                $new->version = 0;
            });
            $app->pending_app_id = $appId;
            $app->pending_app_secret = $secret;
            $app->pending_app_name = $name;
            $app->pending_by = $actor->getKey();
            $app->pending_at = $now;
            $app->activates_at = $alone ? $now->addHours((int) config('meta.single_approver_wait_hours')) : null;
            $app->version++;
            $app->save();

            $this->audit->record(
                action: 'meta.app_change_requested',
                target: $app,
                new: ['app_id' => $appId, 'app_name' => $name, 'activates_at' => $app->activates_at?->toIso8601String()],
                actor: $actor,
                partnerId: $partner->getKey(),
            );

            return $app;
        });
    }

    /** A second partner owner approves the waiting change. */
    public function approve(Partner $partner, User $approver, string $password, int $baseVersion): MetaApp
    {
        $this->identity->assertPassword($approver, $password);
        $app = $this->pendingOf($partner, $baseVersion);
        if ($app->pending_by === $approver->getKey()) {
            throw MetaException::ownChange();
        }

        return $this->apply($app, $approver);
    }

    /** Drops the waiting change (any partner owner, the asker included). */
    public function reject(Partner $partner, User $actor, int $baseVersion): MetaApp
    {
        $app = $this->pendingOf($partner, $baseVersion);

        return DB::transaction(function () use ($app, $actor) {
            $dropped = $app->pending_app_id;
            $this->clearPending($app);
            $app->version++;
            $app->save();
            $this->audit->record(action: 'meta.app_change_rejected', target: $app, old: ['app_id' => $dropped], actor: $actor, partnerId: $app->partner_id);

            return $app;
        });
    }

    /**
     * Applies changes whose single-approver wait is over (scheduled).
     *
     * @return list<string> Ids of the apps changed.
     */
    public function applyDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $done = [];
        foreach (MetaApp::query()->whereNotNull('activates_at')->where('activates_at', '<=', $now)->whereNotNull('pending_app_id')->get() as $app) {
            $done[] = $this->apply($app, null)->getKey();
        }

        return $done;
    }

    /**
     * Partner owners other than this one, who may approve a change.
     *
     * @return Collection<int, User>
     */
    public function approvers(Partner $partner, ?User $except = null): Collection
    {
        return $this->recipients->partnerStaff($partner, PartnerUserRole::Owner)
            ->reject(fn (User $user) => $except !== null && $user->getKey() === $except->getKey())->values();
    }

    // ---------------------------------------------------------------- inside

    private function pendingOf(Partner $partner, int $baseVersion): MetaApp
    {
        $app = $this->of($partner);
        if ($app === null || ! $app->hasPendingChange()) {
            throw MetaException::nothingPending();
        }
        if ($app->version !== $baseVersion) {
            throw MetaException::versionConflict();
        }

        return $app;
    }

    private function apply(MetaApp $app, ?User $approver): MetaApp
    {
        $before = $app->app_id;
        $app = DB::transaction(function () use ($app, $approver) {
            $app = MetaApp::query()->whereKey($app->getKey())->lockForUpdate()->firstOrFail();
            $app->app_id = $app->pending_app_id;
            $app->app_secret = $app->pending_app_secret;
            $app->app_name = $app->pending_app_name;
            $app->status = MetaApp::ACTIVE;
            $app->approved_by = $approver?->getKey();
            $app->approved_at = CarbonImmutable::now();
            $this->clearPending($app);
            $app->version++;
            $app->save();

            $this->audit->record(
                action: $approver === null ? 'meta.app_changed_after_wait' : 'meta.app_changed',
                target: $app,
                new: ['app_id' => $app->app_id, 'app_name' => $app->app_name],
                actor: $approver,
                partnerId: $app->partner_id,
            );

            return $app;
        });

        // Pages signed in through the old app must sign in again (its tokens do not work with the new secret).
        MetaAppChanged::dispatch($app->getKey(), $before !== null && $before !== $app->app_id);

        return $app;
    }

    private function clearPending(MetaApp $app): void
    {
        $app->pending_app_id = null;
        $app->pending_app_secret = null;
        $app->pending_app_name = null;
        $app->pending_by = null;
        $app->pending_at = null;
        $app->activates_at = null;
    }
}
