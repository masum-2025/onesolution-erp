<?php

namespace App\Platform\Tenancy\Actions;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Exceptions\MembershipConflict;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;

/**
 * Gives an existing identity a membership in an organization.
 * (Invitations for people without an account arrive with Phase 5C.)
 */
class AddMember
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(
        Organization $organization,
        User $user,
        MembershipType $type,
        AccessScope $accessScope = AccessScope::Own,
        ?User $actor = null,
    ): OrganizationMembership {
        $exists = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        if ($exists) {
            throw MembershipConflict::alreadyMember();
        }

        $membership = OrganizationMembership::create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'membership_type' => $type,
            'access_scope' => $accessScope,
            'is_primary' => ! $user->memberships()->exists(),
            'status' => MembershipStatus::Active,
            'invited_by' => $actor?->getKey(),
        ]);

        $this->audit->record(
            action: 'membership.added',
            target: $membership,
            new: [
                'user_id' => $user->getKey(),
                'membership_type' => $type->value,
                'access_scope' => $accessScope->value,
            ],
            actor: $actor,
            organizationId: $organization->getKey(),
            partnerId: $organization->partner_id,
        );

        return $membership;
    }
}
