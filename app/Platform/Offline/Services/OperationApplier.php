<?php

namespace App\Platform\Offline\Services;

use App\Models\User;
use App\Platform\Offline\Models\OfflineLease;
use App\Platform\Offline\SyncOperation;
use App\Platform\Offline\SyncRecords;
use App\Platform\Offline\SyncResult;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies one offline change as a fresh request by the person in the current
 * tenant context: the kind must be usable here, money is append-only and
 * needs offline payments allowed, sensitive rules must not have changed
 * since the lease, the organization must not be read-only, the person needs
 * the module's permission, and the module itself validates and checks the
 * version (a stale one is a conflict, never an overwrite).
 */
class OperationApplier
{
    private const ACTIONS = [SyncOperation::CREATE, SyncOperation::UPDATE, SyncOperation::DELETE];

    /** @var array<string, string> lease id => current sensitive fingerprint */
    private array $fingerprints = [];

    public function __construct(
        private SyncRecords $records,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private CurrentContext $context,
        private LeaseService $leases,
    ) {}

    /**
     * @param  array<string, mixed>  $operation  As the device sent it (already shape-checked).
     */
    public function apply(array $operation, User $user, Organization $organization, ?OfflineLease $lease): SyncResult
    {
        $kind = (string) $operation['kind'];
        $action = (string) $operation['action'];

        if (! $this->records->usable($kind, $organization)) {
            return SyncResult::rejected('kind_unavailable');
        }
        if (! in_array($action, self::ACTIONS, true)) {
            return SyncResult::rejected('invalid', ['action' => [__('offline.results.invalid')]]);
        }

        $provider = $this->records->provider($kind);

        if ($provider->isMoney()) {
            if ($action !== SyncOperation::CREATE) {
                return SyncResult::rejected('append_only');
            }
            if (! (bool) $this->rules->get('offline_mode.allow_offline_payments', $this->contexts->current())) {
                return SyncResult::rejected('offline_payments_off');
            }
        }

        if ($lease !== null) {
            $this->fingerprints[$lease->getKey()] ??= $this->leases->currentSensitiveHash($lease);
            if (! hash_equals($lease->sensitive_hash, $this->fingerprints[$lease->getKey()])) {
                return SyncResult::rejected('rules_changed');
            }
        }

        if ($this->context->mode() !== CurrentContext::MODE_NORMAL) {
            return SyncResult::rejected('read_only');
        }

        if (! Gate::forUser($user)->allows($provider->permission($action), $organization)) {
            return SyncResult::rejected('forbidden');
        }

        $sync = new SyncOperation(
            opId: (string) $operation['op_id'],
            kind: $kind,
            action: $action,
            recordId: isset($operation['record_id']) ? (string) $operation['record_id'] : null,
            baseVersion: isset($operation['base_version']) ? (int) $operation['base_version'] : null,
            data: (array) ($operation['data'] ?? []),
            madeAt: $this->time($operation['made_at'] ?? null),
            user: $user,
            organization: $organization,
        );

        try {
            return DB::transaction(fn () => $provider->apply($sync));
        } catch (ValidationException $exception) {
            return SyncResult::rejected('invalid', $exception->errors());
        }
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) ? CarbonImmutable::parse($value)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
