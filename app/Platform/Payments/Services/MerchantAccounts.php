<?php

namespace App\Platform\Payments\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Services\AccountService;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Payments\Contracts\GatewayDriver;
use App\Platform\Payments\Events\MerchantAccountChangeApplied;
use App\Platform\Payments\Events\MerchantAccountChangeRequested;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\MerchantAccount;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A client's own payment gateway accounts (Phase 6). Changing one changes
 * where customers' money goes, so:
 *
 * - credentials are checked with the gateway before anything is saved;
 * - every change (first connection, new credentials, other mode) waits for
 *   a second person who may manage payment accounts here; when there is
 *   nobody else, it takes effect by itself after a wait (rule
 *   online_payments.single_approver_wait_hours);
 * - everyone who could approve is told at once, and every step is audited
 *   (never with a secret);
 * - the person acting confirms with their password (checked by the caller);
 * - turning an account off takes effect at once (it only stops payments).
 *
 * Accounts belong to a company (or personal workspace); its branches and
 * departments collect into it.
 */
class MerchantAccounts
{
    public function __construct(
        private GatewayRegistry $gateways,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private OrganizationSettingsResolver $settings,
        private Recipients $recipients,
        private AccountService $identity,
        private AuditLogger $audit,
    ) {}

    /** The company whose accounts serve this organization (itself, or the company above it). */
    public function companyOf(Organization $organization): ?Organization
    {
        if ($organization->type->isCompanyLike()) {
            return $organization;
        }

        return Organization::query()
            ->whereIn('id', $organization->ancestorIds())
            ->get()
            ->first(fn (Organization $ancestor) => $ancestor->type->isCompanyLike());
    }

    /**
     * Gateways the company may connect now (country rule, narrowed by partner or group).
     *
     * @return list<string>
     */
    public function offered(Organization $company): array
    {
        $keys = (array) $this->rules->get('online_payments.gateways', $this->contexts->forOrganization($company));

        return array_values(array_filter($keys, fn ($key) => is_string($key) && $this->gateways->driver($key) !== null));
    }

    public function liveAllowed(Organization $company): bool
    {
        return (bool) $this->rules->get('online_payments.live_mode_allowed', $this->contexts->forOrganization($company));
    }

    public function currencyOf(Organization $company): string
    {
        return (string) ($this->settings->values($company)['currency_code'] ?? config('tenancy.defaults.currency_code'));
    }

    /**
     * @return Collection<int, MerchantAccount>
     */
    public function accounts(Organization $company): Collection
    {
        return $this->query()->where('organization_id', $company->getKey())->orderBy('gateway')->get();
    }

    public function find(Organization $company, string $id): MerchantAccount
    {
        return $this->query()->where('organization_id', $company->getKey())->whereKey($id)->first()
            ?? throw PaymentException::accountNotFound();
    }

    /**
     * The account a customer's payment goes to: active, offered here, in the
     * company's currency, and live only where live accounts are allowed.
     */
    public function collectingAccount(Organization $company, string $currency): ?MerchantAccount
    {
        $offered = $this->offered($company);
        $live = $this->liveAllowed($company);

        return $this->accounts($company)->first(fn (MerchantAccount $account) => $account->canCollect()
            && $account->currency_code === $currency
            && in_array($account->gateway, $offered, true)
            && ($account->mode !== MerchantAccount::LIVE || $live)
            && in_array($currency, $this->gateways->driver($account->gateway)?->currencies() ?? [], true));
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function connect(Organization $company, User $actor, string $password, string $gateway, string $label, string $mode, array $credentials): MerchantAccount
    {
        $this->identity->assertPassword($actor, $password);
        $driver = $this->usableDriver($company, $gateway, $mode);
        $currency = $this->currencyOf($company);

        if (! in_array($currency, $driver->currencies(), true)) {
            throw PaymentException::currencyNotSupported($currency);
        }
        if ($this->query()->where('organization_id', $company->getKey())->where('gateway', $gateway)->exists()) {
            throw PaymentException::accountExists();
        }

        $this->assertAccepted($driver, $credentials, $mode);

        // Saved with the waiting change below (a unique index stops a second one at once).
        $account = new MerchantAccount;
        $account->forceFill([
            'organization_id' => $company->getKey(),
            'gateway' => $gateway,
            'label' => $label,
            'currency_code' => $currency,
            'status' => MerchantAccount::PENDING,
            'created_by' => $actor->getKey(),
        ]);

        return $this->request($company, $account, $actor, $driver, $mode, $credentials, 'payments.merchant_account.connected');
    }

    /**
     * New credentials and/or mode wait for approval; a new label applies at once.
     *
     * @param  array<string, string>|null  $credentials
     */
    public function change(Organization $company, MerchantAccount $account, User $actor, string $password, int $baseVersion, ?string $label, ?string $mode, ?array $credentials): MerchantAccount
    {
        $this->identity->assertPassword($actor, $password);
        $this->assertVersion($account, $baseVersion);

        if ($label !== null && $label !== $account->label) {
            $old = $account->label;
            $account->forceFill(['label' => $label, 'version' => $account->version + 1])->save();
            $this->record('payments.merchant_account.renamed', $account, ['label' => $old], ['label' => $label]);
        }

        if ($credentials === null) {
            return $account;
        }

        $mode ??= $account->mode ?? MerchantAccount::SANDBOX;
        $driver = $this->usableDriver($company, $account->gateway, $mode);
        $this->assertAccepted($driver, $credentials, $mode);

        return $this->request($company, $account, $actor, $driver, $mode, $credentials, 'payments.merchant_account.change_requested');
    }

    public function approve(MerchantAccount $account, User $approver, string $password, int $baseVersion): MerchantAccount
    {
        $this->identity->assertPassword($approver, $password);
        $this->assertVersion($account, $baseVersion);

        if (! $account->hasPendingChange()) {
            throw PaymentException::nothingPending();
        }
        if ($account->pending_by === $approver->getKey()) {
            throw PaymentException::ownChange();
        }

        return $this->apply($account, $approver);
    }

    /** Drops a waiting change; a never-approved account is switched off. */
    public function reject(MerchantAccount $account, User $actor, int $baseVersion, ?string $reason): MerchantAccount
    {
        $this->assertVersion($account, $baseVersion);

        if (! $account->hasPendingChange()) {
            throw PaymentException::nothingPending();
        }

        $account->forceFill([
            ...$this->clearedPending(),
            'status' => $account->credentials === null ? MerchantAccount::DISABLED : $account->status,
            'version' => $account->version + 1,
        ])->save();

        $this->record('payments.merchant_account.change_rejected', $account, [], [], $reason, $actor);

        return $account;
    }

    /** Stops taking payments into this account at once. */
    public function disable(MerchantAccount $account, User $actor, int $baseVersion, ?string $reason): MerchantAccount
    {
        $this->assertVersion($account, $baseVersion);

        $old = $account->status;
        $account->forceFill(['status' => MerchantAccount::DISABLED, 'version' => $account->version + 1])->save();
        $this->record('payments.merchant_account.disabled', $account, ['status' => $old], ['status' => MerchantAccount::DISABLED], $reason, $actor);

        return $account;
    }

    /** Turns an approved account back on, with the credentials approved before. */
    public function enable(Organization $company, MerchantAccount $account, User $actor, string $password, int $baseVersion): MerchantAccount
    {
        $this->identity->assertPassword($actor, $password);
        $this->assertVersion($account, $baseVersion);

        if ($account->status !== MerchantAccount::DISABLED) {
            throw PaymentException::notDisabled();
        }
        if ($account->credentials === null) {
            throw PaymentException::neverApproved();
        }
        // The rules may have changed while it was off.
        $this->usableDriver($company, $account->gateway, (string) $account->mode);

        $account->forceFill(['status' => MerchantAccount::ACTIVE, 'version' => $account->version + 1])->save();
        $this->record('payments.merchant_account.enabled', $account, ['status' => MerchantAccount::DISABLED], ['status' => MerchantAccount::ACTIVE], null, $actor);

        return $account;
    }

    /** Asks the gateway about the waiting change (or the approved credentials). */
    public function test(MerchantAccount $account): string
    {
        $pending = $account->hasPendingChange();
        $driver = $this->gateways->driver($account->gateway);
        $credentials = $pending ? $account->pending_credentials : $account->credentials;
        $mode = $pending ? $account->pending_mode : $account->mode;

        $result = $driver === null || $credentials === null || $mode === null ? 'unexpected' : $driver->check($credentials, $mode);

        $account->forceFill(['check_result' => $result, 'checked_at' => CarbonImmutable::now()])->save();

        return $result;
    }

    /**
     * Applies changes whose single-approver wait is over (scheduled). Each is
     * checked with the gateway once more; one it no longer accepts stays
     * waiting, with the result shown.
     *
     * @return array{applied: int, failed: int}
     */
    public function applyDue(CarbonImmutable $now): array
    {
        $counts = ['applied' => 0, 'failed' => 0];

        $due = $this->query()->whereNotNull('activates_at')->where('activates_at', '<=', $now)->whereNotNull('pending_credentials')->get();

        foreach ($due as $account) {
            if ($this->test($account) !== 'ok') {
                $counts['failed']++;

                continue;
            }

            $this->apply($account, null);
            $counts['applied']++;
        }

        return $counts;
    }

    /**
     * People other than the actor who may approve a change at this company.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function approvers(Organization $company, ?User $except = null): \Illuminate\Database\Eloquent\Collection
    {
        return $this->recipients->holding('online_payments.manage', $company)
            ->reject(fn (User $user) => $except !== null && $user->is($except))
            ->values();
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function request(Organization $company, MerchantAccount $account, User $actor, GatewayDriver $driver, string $mode, array $credentials, string $action): MerchantAccount
    {
        $alone = $this->approvers($company, $actor)->isEmpty();
        $hours = (int) $this->rules->get('online_payments.single_approver_wait_hours', $this->contexts->forOrganization($company));
        $now = CarbonImmutable::now();

        $account->forceFill([
            'pending_mode' => $mode,
            'pending_credentials' => $this->only($driver, $credentials),
            'pending_hint' => $driver->hint($credentials),
            'pending_by' => $actor->getKey(),
            'pending_at' => $now,
            'activates_at' => $alone ? $now->addHours($hours) : null,
            'check_result' => 'ok',
            'checked_at' => $now,
            'version' => $account->exists ? $account->version + 1 : 1,
        ]);

        try {
            $account->save();
        } catch (UniqueConstraintViolationException) {
            // The same gateway connected twice at once: the first one stands.
            throw PaymentException::accountExists();
        }

        $this->record($action, $account, [
            'mode' => $account->mode,
            'hint' => $account->credential_hint,
        ], [
            'gateway' => $account->gateway,
            'mode' => $mode,
            'hint' => $account->pending_hint,
            'activates_at' => $account->activates_at?->toIso8601String(),
        ], null, $actor);

        MerchantAccountChangeRequested::dispatch($account, $actor);

        return $account;
    }

    private function apply(MerchantAccount $account, ?User $approver): MerchantAccount
    {
        $account = DB::transaction(function () use ($account, $approver) {
            $account = $this->query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $old = ['mode' => $account->mode, 'hint' => $account->credential_hint, 'status' => $account->status];

            $account->forceFill([
                'mode' => $account->pending_mode,
                'credentials' => $account->pending_credentials,
                'credential_hint' => $account->pending_hint,
                // A disabled account stays off: approving new details does not turn it on.
                'status' => $account->status === MerchantAccount::DISABLED && $account->credentials !== null ? MerchantAccount::DISABLED : MerchantAccount::ACTIVE,
                'approved_by' => $approver?->getKey(),
                'approved_at' => CarbonImmutable::now(),
                ...$this->clearedPending(),
                'version' => $account->version + 1,
            ])->save();

            $this->record(
                $approver === null ? 'payments.merchant_account.applied_after_wait' : 'payments.merchant_account.approved',
                $account,
                $old,
                ['mode' => $account->mode, 'hint' => $account->credential_hint, 'status' => $account->status],
                null,
                $approver,
            );

            return $account;
        });

        MerchantAccountChangeApplied::dispatch($account, $approver);

        return $account;
    }

    private function usableDriver(Organization $company, string $gateway, string $mode): GatewayDriver
    {
        if (! in_array($gateway, $this->offered($company), true)) {
            throw PaymentException::gatewayNotOffered();
        }
        if ($mode === MerchantAccount::LIVE && ! $this->liveAllowed($company)) {
            throw PaymentException::liveNotAllowed();
        }

        return $this->gateways->driver($gateway) ?? throw PaymentException::gatewayNotOffered();
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function assertAccepted(GatewayDriver $driver, array $credentials, string $mode): void
    {
        $result = $driver->check($this->only($driver, $credentials), $mode);

        if ($result !== 'ok') {
            throw PaymentException::checkFailed($result);
        }
    }

    /**
     * Only the fields the gateway uses are ever stored.
     *
     * @param  array<string, string>  $credentials
     * @return array<string, string>
     */
    private function only(GatewayDriver $driver, array $credentials): array
    {
        return array_map('strval', array_intersect_key($credentials, $driver->credentialFields()));
    }

    /**
     * @return array<string, null>
     */
    private function clearedPending(): array
    {
        return [
            'pending_mode' => null,
            'pending_credentials' => null,
            'pending_hint' => null,
            'pending_by' => null,
            'pending_at' => null,
            'activates_at' => null,
        ];
    }

    private function assertVersion(MerchantAccount $account, int $baseVersion): void
    {
        if ($account->version !== $baseVersion) {
            throw PaymentException::stale();
        }
    }

    /**
     * Audited without secrets: gateway, mode, hint and status only.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function record(string $action, MerchantAccount $account, array $old, array $new, ?string $reason = null, ?User $actor = null): void
    {
        $company = Organization::query()->find($account->organization_id);

        $this->audit->record(
            action: $action,
            target: $account,
            old: array_filter($old, fn ($value) => $value !== null),
            new: ['gateway' => $account->gateway, ...array_filter($new, fn ($value) => $value !== null)],
            reason: $reason,
            actor: $actor,
            organizationId: $account->organization_id,
            partnerId: $company?->partner_id,
        );
    }

    /** System code (the scheduled apply) runs without a context. */
    private function query()
    {
        return MerchantAccount::query()->withoutGlobalScope(OrganizationScope::class);
    }
}
