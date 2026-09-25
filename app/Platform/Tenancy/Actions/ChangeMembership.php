<?php

namespace App\Platform\Tenancy\Actions;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Exceptions\MembershipConflict;
use App\Platform\Tenancy\Models\OrganizationMembership;
use BackedEnum;

/**
 * Changes status, type or reach of a membership. Nobody can change their own
 * membership, so an owner cannot lock themselves (or the org) out by mistake.
 */
class ChangeMembership
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{status?: string, membership_type?: string, access_scope?: string}  $attributes
     */
    public function handle(OrganizationMembership $membership, array $attributes, User $actor): OrganizationMembership
    {
        if ($membership->user_id === $actor->getKey()) {
            throw MembershipConflict::ownMembership();
        }

        $membership->fill($attributes);

        if (! $membership->isDirty()) {
            return $membership;
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
}
