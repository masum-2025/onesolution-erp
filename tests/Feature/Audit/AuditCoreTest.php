<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Audit\AuditLogger;
use App\Platform\Audit\Shipping\AuditShipping;
use App\Platform\Audit\Shipping\Contracts\AuditShipper;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Tenancy\Enums\MembershipType;
use Illuminate\Support\Str;

/*
 * Phase 9-1, core audit (always on, every plan): money, payroll, rules,
 * modules, permissions and sign-ins are recorded even while advanced_audit
 * is off; entries name the device or session; filters; external shipping.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->c1);
});

/**
 * An entry at a chosen time (fixtures; the app never sets created_at itself).
 */
function auditEntry(?string $organizationId, string $action, string $when = 'now', ?string $actor = null, array $attributes = []): AuditLog
{
    $entry = new AuditLog;
    $entry->forceFill([
        'organization_id' => $organizationId,
        'action' => $action,
        'actor_user_id' => $actor,
        'created_at' => now()->modify($when),
        ...$attributes,
    ])->save();

    return $entry;
}

it('records a payroll rule change while advanced_audit is off', function () {
    toggles()->enable($this->w->c1, 'payroll', 'Test setup');
    expect(resolvedModule('advanced_audit', $this->w->c1)->enabled)->toBeFalse();

    orgRule($this->w->c1, 'payroll.overtime_multiplier', '2', actor: $this->owner);

    $entry = AuditLog::query()->where('action', 'rule.changed')->where('organization_id', $this->w->c1->id)->sole();
    expect($entry->new_values['rule'])->toBe('payroll.overtime_multiplier')
        ->and($entry->actor_user_id)->toBe($this->owner->id);
});

it('names the offline device a change came from', function () {
    $device = (string) Str::ulid();
    request()->attributes->set(AuditLogger::DEVICE_ATTRIBUTE, $device);

    $entry = app(AuditLogger::class)->record('offline.test', organizationId: $this->w->c1->id);

    expect($entry->device_id)->toBe($device)->and($entry->session_id)->toBeNull();
});

it('names the browser session a change was made in', function () {
    spaSession($this, $this->owner, $this->w->c1);

    // The same browser: the session cookie goes with the request.
    $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId())
        ->patchJson("/api/organizations/{$this->w->c1->id}", ['name' => ['en' => 'C1 renamed']])->assertOk();

    // One of this person's signed-in browsers (entering an organization renews the session).
    $sessions = UserSession::query()->where('user_id', $this->owner->id)->pluck('id')->all();
    expect(AuditLog::query()->where('action', 'organization.updated')->sole()->session_id)->not->toBeNull()->toBeIn($sessions);
});

it('filters the log by period, area and person, within the organization only', function () {
    $staff = createMember($this->w->c1, MembershipType::Staff);
    AuditLog::query()->toBase()->delete();
    auditEntry($this->w->c1->id, 'rule.changed', '-10 days', $this->owner->id);
    auditEntry($this->w->b1->id, 'rule.reset', '-2 days', $staff->id);
    auditEntry($this->w->c1->id, 'module.enabled', '-2 days', $this->owner->id);
    auditEntry($this->w->c2->id, 'rule.changed', '-2 days', $this->owner->id);
    $url = "/api/organizations/{$this->w->c1->id}/audit-log";
    $token = orgToken($this->owner, $this->w->c1);
    $from = now()->subDays(5)->toDateString();

    $actions = fn (array $query) => collect($this->asToken($token)->getJson($url.'?'.http_build_query($query))->assertOk()->json('data'))
        ->pluck('action')->filter(fn ($action) => $action !== 'auth.context_entered')->sort()->values()->all();

    expect($actions(['from' => $from]))->toBe(['module.enabled', 'rule.reset'])
        ->and($actions(['action' => 'rule']))->toBe(['rule.changed', 'rule.reset'])
        ->and($actions(['action' => 'rule.reset']))->toBe(['rule.reset'])
        ->and($actions(['actor' => $staff->id]))->toBe(['rule.reset']);

    $this->asToken($token)->getJson("{$url}?from=2026-13-01")->assertUnprocessable()->assertJsonValidationErrors('from');
    $this->asToken($token)->getJson("{$url}?sort=name")->assertUnprocessable()->assertJsonValidationErrors('sort');
});

it('ships entries to the external store in order, once each, after the lag', function () {
    $shipped = new class implements AuditShipper
    {
        public array $ids = [];

        public function name(): string
        {
            return 'test';
        }

        public function ship(array $entries): void
        {
            array_push($this->ids, ...array_column($entries, 'id'));
        }
    };
    app()->instance(AuditShipper::class, $shipped);
    config(['audit.shipping.batch' => 2, 'audit.shipping.lag_seconds' => 60]);
    AuditLog::query()->toBase()->delete();

    $first = auditEntry($this->w->c1->id, 'rule.changed', '-5 minutes');
    $second = auditEntry($this->w->c1->id, 'rule.reset', '-4 minutes');
    $third = auditEntry(null, 'backup.created', '-3 minutes');
    $young = auditEntry($this->w->c1->id, 'module.enabled', '-10 seconds');

    expect(app(AuditShipping::class)->run())->toBe(3)
        ->and($shipped->ids)->toBe([$first->id, $second->id, $third->id])
        ->and(app(AuditShipping::class)->run())->toBe(0);

    $this->travel(2)->minutes();
    expect(app(AuditShipping::class)->run())->toBe(1)
        ->and($shipped->ids)->toBe([$first->id, $second->id, $third->id, $young->id]);
});

it('does nothing while shipping is off', function () {
    config(['audit.shipping.driver' => 'none']);

    $this->artisan('audit:ship')->expectsOutputToContain('off')->assertSuccessful();
});
