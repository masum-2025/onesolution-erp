<?php

namespace App\Platform\Offline\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Offline\Events\OperationQuarantined;
use App\Platform\Offline\Exceptions\OfflineException;
use App\Platform\Offline\Models\Device;
use App\Platform\Offline\Models\QuarantinedOperation;
use App\Platform\Offline\Models\SyncOperationRecord;
use App\Platform\Offline\SyncRecords;
use App\Platform\Offline\SyncResult;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Offline changes that arrive from a device or person no longer allowed
 * (removed device, membership ended, permission gone, offline mode off) are
 * never applied on their own: they are held, and someone with
 * offline_mode.manage releases them (applied now, as that person's own
 * fresh request, so every check runs again) or discards them. Held changes
 * not decided in time (rule offline_mode.quarantine_days) are discarded.
 */
class QuarantineService
{
    public function __construct(
        private OperationApplier $applier,
        private SyncRecords $records,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private CurrentContext $context,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $operation  As the device sent it.
     */
    public function hold(Device $device, ?User $user, Organization $organization, array $operation, string $reason): SyncResult
    {
        $kind = (string) ($operation['kind'] ?? '');
        $money = $this->records->has($kind) && $this->records->provider($kind)->isMoney();
        $days = (int) $this->rules->get('offline_mode.quarantine_days', $this->contexts->forOrganization($organization));
        $result = SyncResult::quarantined($reason);

        DB::transaction(function () use ($device, $user, $organization, $operation, $reason, $kind, $money, $days, $result) {
            $held = new QuarantinedOperation;
            $held->forceFill([
                'device_id' => $device->getKey(),
                'organization_id' => $organization->getKey(),
                'user_id' => $user?->getKey(),
                'op_id' => (string) $operation['op_id'],
                'kind' => mb_substr($kind, 0, 60),
                'action' => mb_substr((string) ($operation['action'] ?? ''), 0, 10),
                'money' => $money,
                'payload' => $operation,
                'reason' => $reason,
                'status' => QuarantinedOperation::PENDING,
                'expires_at' => CarbonImmutable::now()->addDays($days),
            ])->save();

            (new SyncOperationRecord)->forceFill([
                'device_id' => $device->getKey(),
                'organization_id' => $organization->getKey(),
                'user_id' => $device->user_id,
                'op_id' => (string) $operation['op_id'],
                'kind' => mb_substr($kind, 0, 60),
                'action' => mb_substr((string) ($operation['action'] ?? ''), 0, 10),
                'record_id' => isset($operation['record_id']) ? mb_substr((string) $operation['record_id'], 0, 26) : null,
                'status' => SyncResult::QUARANTINED,
                'result' => $result->toArray(),
                'made_at' => $this->time($operation['made_at'] ?? null),
                'received_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(
                action: 'offline.operation_quarantined',
                target: $held,
                new: ['kind' => $kind, 'action' => $held->action, 'money' => $money, 'reason' => $reason, 'person' => $user?->getKey()],
                organizationId: $organization->getKey(),
                partnerId: $organization->partner_id,
            );

            OperationQuarantined::dispatch($held);
        });

        return $result;
    }

    /** Applies a held change now, as the deciding person's own request. */
    public function release(QuarantinedOperation $held, User $actor): SyncResult
    {
        return DB::transaction(function () use ($held, $actor) {
            $held = QuarantinedOperation::query()->whereKey($held->getKey())->lockForUpdate()->firstOrFail();
            if ($held->status !== QuarantinedOperation::PENDING) {
                throw OfflineException::alreadyDecided();
            }

            $result = $this->applier->apply($held->payload, $actor, $this->context->organization(), lease: null);

            $held->forceFill([
                'status' => QuarantinedOperation::RELEASED,
                'decided_by' => $actor->getKey(),
                'decided_at' => CarbonImmutable::now(),
                'decision_result' => $result->toArray(),
            ])->save();

            $this->record($held, 'offline.quarantine_released', $actor, ['result' => $result->status]);

            return $result;
        });
    }

    public function discard(QuarantinedOperation $held, User $actor, ?string $reason): void
    {
        DB::transaction(function () use ($held, $actor, $reason) {
            $held = QuarantinedOperation::query()->whereKey($held->getKey())->lockForUpdate()->firstOrFail();
            if ($held->status !== QuarantinedOperation::PENDING) {
                throw OfflineException::alreadyDecided();
            }

            $held->forceFill(['status' => QuarantinedOperation::DISCARDED, 'decided_by' => $actor->getKey(), 'decided_at' => CarbonImmutable::now()])->save();
            $this->record($held, 'offline.quarantine_discarded', $actor, [], $reason);
        });
    }

    /** Held changes nobody decided on in time are discarded. Safe to repeat. */
    public function discardExpired(CarbonImmutable $now): int
    {
        $count = 0;
        QuarantinedOperation::query()->where('status', QuarantinedOperation::PENDING)->where('expires_at', '<=', $now)->lazyById()
            ->each(function (QuarantinedOperation $held) use (&$count) {
                $held->forceFill(['status' => QuarantinedOperation::DISCARDED, 'decided_at' => CarbonImmutable::now()])->save();
                $this->record($held, 'offline.quarantine_expired', null, []);
                $count++;
            });

        return $count;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(QuarantinedOperation $held, string $action, ?User $actor, array $extra, ?string $reason = null): void
    {
        $this->audit->record(
            action: $action,
            target: $held,
            new: ['kind' => $held->kind, 'action' => $held->action, 'money' => $held->money, 'person' => $held->user_id, ...$extra],
            reason: $reason,
            actor: $actor,
            organizationId: $held->organization_id,
            partnerId: $held->organization?->partner_id,
        );
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) ? CarbonImmutable::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
