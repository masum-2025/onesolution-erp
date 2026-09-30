<?php

namespace App\Platform\Offline\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Offline\Exceptions\OfflineException;
use App\Platform\Offline\Models\Device;
use App\Platform\Offline\Models\OfflineLease;
use App\Platform\Offline\Models\SyncOperationRecord;
use App\Platform\Offline\SyncRecords;
use App\Platform\Offline\SyncResult;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * POST /api/sync, in this order (Phase 7):
 *
 *  a. the person, the device (theirs, not removed), the organization (active)
 *     and offline mode (on) are checked again;
 *  b. the lease: genuine signature, this device, person and organization, not
 *     expired or withdrawn;
 *  c. an op_id seen before returns its stored result, never applies twice;
 *  d-f. each change is applied as a fresh request (OperationApplier): scope,
 *     permission, validation, version (conflicts, not overwrites), money
 *     append-only; it must come from a lease this device was given, made
 *     before that lease ended;
 *  g. changes from a removed device, an ended membership, a lost permission
 *     or with offline mode off are held for an admin (QuarantineService), and
 *     the device is told to wipe;
 *  h. the answer: a result per change, what changed on the server since the
 *     device's cursor (deletions too), and a new lease.
 */
class SyncService
{
    public function __construct(
        private LeaseSigner $signer,
        private LeaseService $leases,
        private OperationApplier $applier,
        private QuarantineService $quarantine,
        private DeviceService $devices,
        private SyncRecords $records,
        private ModuleResolver $modules,
        private ContextResolver $resolver,
        private CurrentContext $context,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return array{status: int, body: array<string, mixed>}
     */
    public function sync(User $user, string $deviceId, string $token, array $operations, ?string $cursor): array
    {
        // a. The device is the person's own; unknown ones get nothing at all.
        $device = Device::query()->with('organization')->whereKey($deviceId)->where('user_id', $user->getKey())->first()
            ?? throw OfflineException::deviceNotFound();
        $device->forceFill(['last_seen_at' => CarbonImmutable::now()])->save();
        // Audit entries written while applying this device's changes name it (Phase 9-1).
        request()->attributes->set(AuditLogger::DEVICE_ATTRIBUTE, $device->getKey());
        $organization = $device->organization;

        if ($device->isRevoked() || $device->mustWipe()) {
            return $this->holdAndWipe($device, $user, $organization, $operations, $device->isRevoked() ? 'device_revoked' : 'module_off');
        }

        if (! $organization->isActive()) {
            throw OfflineException::organizationInactive();
        }

        if (! $this->modules->isEnabled('offline_mode', $organization)) {
            $this->devices->wipeAll([$organization->getKey()], null, 'module_off');

            return $this->holdAndWipe($device->refresh(), $user, $organization, $operations, 'module_off');
        }

        $membership = OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $user->getKey())->first();
        if ($membership === null || $membership->status !== MembershipStatus::Active) {
            $this->devices->revoke($device, $user, 'Membership ended.');

            return $this->holdAndWipe($device->refresh(), $user, $organization, $operations, 'membership_ended');
        }

        // b. The lease the device presents now.
        $lease = $this->verifiedLease($token, $device, $user, $organization);

        try {
            $this->resolver->enterOrganization($user, $organization->getKey(), checkSignIn: false);
        } catch (OrganizationAccessDenied) {
            throw OfflineException::organizationInactive();
        }

        if (! Gate::allows('offline_mode.use', $organization)) {
            $this->devices->revoke($device, $user, 'Permission to work offline was removed.');

            return $this->holdAndWipe($device->refresh(), $user, $organization, $operations, 'permission_missing');
        }

        $max = (int) $this->rules->get('offline_mode.sync_batch_max', $this->contexts->current());
        if (count($operations) > $max) {
            throw OfflineException::tooMany($max);
        }

        $since = $this->cursor($cursor);
        $now = CarbonImmutable::now();

        $results = [];
        foreach ($operations as $operation) {
            $results[(string) $operation['op_id']] = $this->one($device, $user, $organization, $operation)->toArray();
        }

        $changes = [];
        $limit = (int) $this->rules->get('offline_mode.max_cached_records', $this->contexts->current());
        foreach ($this->records->available($organization) as $kind) {
            $changes[$kind] = $this->records->provider($kind)->changes($organization, $since, $limit);
        }

        $device->forceFill(['last_sync_at' => $now])->save();
        $renewed = $this->leases->issue($device);

        return ['status' => 200, 'body' => [
            'wipe' => false,
            'results' => $results,
            'changes' => $changes,
            'cursor' => $now->toIso8601String(),
            // The new lease, as the device keeps it (token, id, rules, kinds, until when).
            ...$this->leases->describe($renewed),
        ]];
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function one(Device $device, User $user, Organization $organization, array $operation): SyncResult
    {
        // c. Seen before: the same answer again.
        $seen = SyncOperationRecord::query()->where('device_id', $device->getKey())->where('op_id', (string) $operation['op_id'])->first();
        if ($seen !== null) {
            return SyncResult::fromArray($seen->result);
        }

        // The lease the change was made under: given to this device, and the change made before it ended.
        $madeUnder = OfflineLease::query()->whereKey((string) ($operation['lease_id'] ?? ''))->where('device_id', $device->getKey())->first();
        $madeAt = $this->time($operation['made_at'] ?? null);

        $result = match (true) {
            $madeUnder === null => SyncResult::rejected('lease_unknown'),
            $madeAt === null || $madeAt->greaterThan($madeUnder->expires_at) || $madeUnder->revoked_at !== null && $madeAt->greaterThan($madeUnder->revoked_at) => SyncResult::rejected('made_after_lease'),
            default => $this->applier->apply($operation, $user, $organization, $madeUnder),
        };

        // Not decided yet (the client's data is moving): nothing is stored, the device sends it again.
        if ($result->status === SyncResult::RETRY_LATER) {
            return $result;
        }

        (new SyncOperationRecord)->forceFill([
            'device_id' => $device->getKey(),
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'op_id' => (string) $operation['op_id'],
            'kind' => mb_substr((string) $operation['kind'], 0, 60),
            'action' => mb_substr((string) $operation['action'], 0, 10),
            'record_id' => $result->recordId ?? (isset($operation['record_id']) ? mb_substr((string) $operation['record_id'], 0, 26) : null),
            'status' => $result->status,
            'result' => $result->toArray(),
            'made_at' => $madeAt,
            'received_at' => CarbonImmutable::now(),
        ])->save();

        return $result;
    }

    private function verifiedLease(string $token, Device $device, User $user, Organization $organization): OfflineLease
    {
        $payload = $this->signer->verify($token);
        if ($payload === null
            || ($payload['dev'] ?? null) !== $device->getKey()
            || ($payload['usr'] ?? null) !== $user->getKey()
            || ($payload['org'] ?? null) !== $organization->getKey()) {
            throw OfflineException::leaseInvalid();
        }

        $lease = OfflineLease::query()->whereKey((string) ($payload['lid'] ?? ''))->where('device_id', $device->getKey())->first();
        if ($lease === null || $lease->revoked_at !== null) {
            throw OfflineException::leaseInvalid();
        }
        if (! $lease->expires_at->isFuture()) {
            throw OfflineException::leaseExpired();
        }

        return $lease;
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return array{status: int, body: array<string, mixed>}
     */
    private function holdAndWipe(Device $device, User $user, Organization $organization, array $operations, string $reason): array
    {
        $results = [];
        foreach ($operations as $operation) {
            $seen = SyncOperationRecord::query()->where('device_id', $device->getKey())->where('op_id', (string) $operation['op_id'])->first();
            $results[(string) $operation['op_id']] = $seen !== null
                ? $seen->result
                : $this->quarantine->hold($device, $user, $organization, $operation, $reason)->toArray();
        }

        return ['status' => 410, 'body' => [
            'code' => $reason,
            'message' => __("offline.results.{$reason}"),
            'wipe' => true,
            'results' => $results,
        ]];
    }

    private function cursor(?string $cursor): ?CarbonImmutable
    {
        return $this->time($cursor);
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
