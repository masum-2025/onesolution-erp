<?php

namespace App\Platform\Portal\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Portal\Exceptions\PortalException;
use App\Platform\Portal\Models\PortalLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The client decides who sees what: approve or reject a waiting link, and
 * revoke an active one (it stops working at once). Every decision is audited.
 */
class PortalLinks
{
    public function __construct(private AuditLogger $audit) {}

    public function approve(PortalLink $link, User $actor): PortalLink
    {
        return $this->decide($link, $actor, PortalLink::ACTIVE, [PortalLink::PENDING], 'portal.link_approved', null);
    }

    public function reject(PortalLink $link, User $actor, ?string $reason): PortalLink
    {
        return $this->decide($link, $actor, PortalLink::REJECTED, [PortalLink::PENDING], 'portal.link_rejected', $reason);
    }

    public function revoke(PortalLink $link, User $actor, ?string $reason): PortalLink
    {
        return $this->decide($link, $actor, PortalLink::REVOKED, [PortalLink::PENDING, PortalLink::ACTIVE], 'portal.link_revoked', $reason);
    }

    /**
     * @param  list<string>  $from
     */
    private function decide(PortalLink $link, User $actor, string $to, array $from, string $action, ?string $reason): PortalLink
    {
        return DB::transaction(function () use ($link, $actor, $to, $from, $action, $reason) {
            $link = PortalLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($link->status, $from, true)) {
                throw PortalException::linkNotPending();
            }

            $old = $link->status;
            $link->forceFill([
                'status' => $to,
                'decided_by' => $actor->getKey(),
                'decided_at' => CarbonImmutable::now(),
                'reason' => $reason,
            ])->save();

            $this->audit->record(
                action: $action,
                target: $link,
                old: ['status' => $old],
                new: ['status' => $to, 'subject_type' => $link->subject_type, 'subject_id' => $link->subject_id, 'member' => $link->user_id],
                reason: $reason,
                actor: $actor,
                organizationId: $link->organization_id,
                partnerId: $link->organization?->partner_id,
            );

            return $link;
        });
    }
}
