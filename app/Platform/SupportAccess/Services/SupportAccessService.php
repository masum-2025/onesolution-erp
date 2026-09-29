<?php

namespace App\Platform\SupportAccess\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\SupportAccess\Enums\GrantStatus;
use App\Platform\SupportAccess\Enums\Severity;
use App\Platform\SupportAccess\Events\SupportAccessDecided;
use App\Platform\SupportAccess\Events\SupportAccessRequested;
use App\Platform\SupportAccess\Exceptions\SupportException;
use App\Platform\SupportAccess\Models\SupportGrant;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;

/**
 * Every step of break-glass support access: request, approval (by a client
 * admin or the client's auto-approval rule), rejection, early end, expiry.
 * Each step is written to the client organization's audit log, so the
 * client sees exactly who came in, why, and for how long.
 */
class SupportAccessService
{
    public function __construct(
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    public function maxMinutes(Organization $organization): int
    {
        return (int) $this->rules->get('support.max_duration_minutes', $this->contexts->forOrganization($organization));
    }

    public function request(Partner $partner, Organization $organization, User $staff, string $reason, Severity $severity, int $minutes): SupportGrant
    {
        $max = $this->maxMinutes($organization);
        if ($minutes > $max) {
            throw SupportException::tooLong($max);
        }

        $autoApprove = in_array(
            $severity->value,
            (array) $this->rules->get('support.auto_approve_severities', $this->contexts->forOrganization($organization)),
            true,
        );

        return DB::transaction(function () use ($partner, $organization, $staff, $reason, $severity, $minutes, $autoApprove) {
            $open = SupportGrant::query()
                ->where('organization_id', $organization->getKey())
                ->where('requested_by', $staff->getKey())
                ->where(fn ($query) => $query
                    ->where('status', GrantStatus::Pending)
                    ->orWhere(fn ($q) => $q->where('status', GrantStatus::Approved)->where('expires_at', '>', now())))
                ->lockForUpdate()
                ->exists();

            if ($open) {
                throw SupportException::alreadyOpen();
            }

            $grant = SupportGrant::create([
                'partner_id' => $partner->getKey(),
                'organization_id' => $organization->getKey(),
                'requested_by' => $staff->getKey(),
                'reason' => $reason,
                'severity' => $severity,
                'access' => 'read',
                'duration_minutes' => $minutes,
                'status' => $autoApprove ? GrantStatus::Approved : GrantStatus::Pending,
                'auto_approved' => $autoApprove,
                'decided_at' => $autoApprove ? now() : null,
                'starts_at' => $autoApprove ? now() : null,
                'expires_at' => $autoApprove ? now()->addMinutes($minutes) : null,
            ]);

            $this->record('support.requested', $grant, $staff, $reason, ['severity' => $severity->value, 'minutes' => $minutes]);

            if ($autoApprove) {
                // Approved by the client's own rule (support.auto_approve_severities), not by the requester.
                $this->record('support.auto_approved', $grant, null, null, ['by' => 'client_rule', 'severity' => $severity->value, 'expires_at' => $grant->expires_at->toIso8601String()]);
            } else {
                SupportAccessRequested::dispatch($grant);
            }

            return $grant;
        });
    }

    public function approve(SupportGrant $grant, User $approver, ?string $reason = null): SupportGrant
    {
        return $this->decide($grant, function (SupportGrant $locked) use ($approver, $reason) {
            if ($locked->status !== GrantStatus::Pending) {
                throw SupportException::notPending();
            }

            $locked->forceFill([
                'status' => GrantStatus::Approved,
                'decided_by' => $approver->getKey(),
                'decided_at' => now(),
                'decision_reason' => $reason,
                // The time starts when the client says yes.
                'starts_at' => now(),
                'expires_at' => now()->addMinutes($locked->duration_minutes),
            ])->save();

            $this->record('support.approved', $locked, $approver, $reason, ['expires_at' => $locked->expires_at->toIso8601String()]);
            SupportAccessDecided::dispatch($locked);
        });
    }

    public function reject(SupportGrant $grant, User $reviewer, string $reason): SupportGrant
    {
        return $this->decide($grant, function (SupportGrant $locked) use ($reviewer, $reason) {
            if ($locked->status !== GrantStatus::Pending) {
                throw SupportException::notPending();
            }

            $locked->forceFill([
                'status' => GrantStatus::Rejected,
                'decided_by' => $reviewer->getKey(),
                'decided_at' => now(),
                'decision_reason' => $reason,
            ])->save();

            $this->record('support.rejected', $locked, $reviewer, $reason);
            SupportAccessDecided::dispatch($locked);
        });
    }

    /**
     * End access early (the client, or the requester who no longer needs it), or withdraw a request.
     */
    public function revoke(SupportGrant $grant, User $actor, string $reason): SupportGrant
    {
        return $this->decide($grant, function (SupportGrant $locked) use ($actor, $reason) {
            $open = $locked->status === GrantStatus::Pending || $locked->isUsable();
            if (! $open) {
                throw SupportException::notActive();
            }

            $locked->forceFill(['status' => GrantStatus::Revoked, 'ended_at' => now(), 'decision_reason' => $reason])->save();

            $this->record('support.revoked', $locked, $actor, $reason);
        });
    }

    /**
     * Close grants whose time has run out (scheduled every minute; the
     * context check refuses them already, this makes it visible in the log).
     */
    public function expireDue(): int
    {
        $count = 0;

        SupportGrant::query()
            ->where('status', GrantStatus::Approved)
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->each(function (SupportGrant $grant) use (&$count) {
                DB::transaction(function () use ($grant) {
                    $grant->forceFill(['status' => GrantStatus::Expired, 'ended_at' => $grant->expires_at])->save();
                    $this->record('support.expired', $grant, null, null, ['ended_at' => $grant->expires_at->toIso8601String()]);
                });
                $count++;
            });

        return $count;
    }

    /**
     * @param  callable(SupportGrant): void  $change
     */
    private function decide(SupportGrant $grant, callable $change): SupportGrant
    {
        return DB::transaction(function () use ($grant, $change) {
            $locked = SupportGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();
            $change($locked);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function record(string $action, SupportGrant $grant, ?User $actor, ?string $reason, array $details = []): void
    {
        $this->audit->record(
            action: $action,
            target: $grant,
            new: ['grant' => $grant->getKey(), 'requested_by' => $grant->requested_by, ...$details],
            reason: $reason,
            actor: $actor,
            // Always in the client's own log, whoever acts.
            organizationId: $grant->organization_id,
            partnerId: $grant->partner_id,
        );
    }
}
