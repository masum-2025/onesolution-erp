<?php

namespace App\Platform\Tenancy\Actions;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Packaging\Services\UsageLimiter;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Exceptions\MembershipConflict;
use App\Platform\Tenancy\Models\OrganizationMembership;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/**
 * Changes status, type or reach of a membership. Nobody can change their own
 * membership, so an owner cannot lock themselves (or the org) out by mistake.
 */
class ChangeMembership
{
    public function __construct(private AuditLogger $audit, private UsageLimiter $limits) {}

    /**
     * @param  array{status?: string, membership_type?: string, access_scope?: string}  $attributes
     */
    public function handle(OrganizationMembership $membership, array $attributes, User $actor): OrganizationMembership
    {
        if ($membership->user_id === $actor->getKey()) {
            throw MembershipConflict::ownMembership();
        }

        $tookSeat = $this->takesSeat($membership);
        $membership->fill($attributes);

        if (! $membership->isDirty()) {
            return $membership;
        }

        return DB::transaction(fn () => $this->save($membership, $actor, $tookSeat));
    }

    private function save(OrganizationMembership $membership, User $actor, bool $tookSeat): OrganizationMembership
    {
        // Reactivating someone, or making a portal user staff, needs a free seat in the plan.
        if (! $tookSeat && $this->takesSeat($membership)) {
            $this->limits->assertSeatAvailable($membership->organization, $membership->user, $membership->membership_type, $membership->getKey());
        }

        $changed = array_keys($membership->getDirty());
        $old = array_map(
            fn ($value) => $value instanceof BackedEnum ? $value->value : $value,
            array_intersect_key($membership->getOriginal(), array_flip($changed)),
        );

        $membership->save();

        $this->audit->record(
            action: 'membership.changed',
            target: $membership,
            old: $old,
            new: array_intersect_key($membership->attributesToArray(), array_flip($changed)),
            actor: $actor,
            organizationId: $membership->organization_id,
        );

        return $membership;
    }

    private function takesSeat(OrganizationMembership $membership): bool
    {
        return in_array($membership->membership_type, [MembershipType::Owner, MembershipType::Staff], true)
            && in_array($membership->status, [MembershipStatus::Active, MembershipStatus::Invited], true);
    }
}
