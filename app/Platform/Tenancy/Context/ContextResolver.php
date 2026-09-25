<?php

namespace App\Platform\Tenancy\Context;

use App\Models\User;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\PartnerUser;
use App\Platform\Tenancy\Services\HierarchyService;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;

/**
 * Turns verified ids (from the API token) into a CurrentContext.
 *
 * Visibility in Phase 1:
 *  - company / branch / department member: the whole company subtree
 *    (branch-level restriction comes with Phase 4 permissions);
 *  - group member with access_scope=descendants: read every company in the
 *    group, write only to the group itself (read-only aggregate);
 *  - group member with access_scope=own: the group node only.
 */
class ContextResolver
{
    public function __construct(
        private CurrentContext $context,
        private HierarchyService $hierarchy,
        private OrganizationSettingsResolver $settings,
    ) {}

    public function enterOrganization(User $user, string $organizationId): CurrentContext
    {
        $membership = OrganizationMembership::query()
            ->with('organization.partner')
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organizationId)
            ->first();

        if ($membership === null || ! $membership->isActive()) {
            throw OrganizationAccessDenied::notMember();
        }

        $organization = $membership->organization;

        if (! $organization->partner->isActive()) {
            throw OrganizationAccessDenied::partnerInactive();
        }

        $ancestors = $this->hierarchy->ancestors($organization);

        // A suspended or archived organization also blocks everything below it.
        if (! $organization->isActive() || $ancestors->contains(fn (Organization $node) => ! $node->isActive())) {
            throw OrganizationAccessDenied::organizationInactive();
        }

        $chain = $ancestors->concat([$organization]);
        $company = $chain->reverse()->first(fn (Organization $node) => $node->type === OrganizationType::Company);
        $group = $chain->first(fn (Organization $node) => $node->type === OrganizationType::Group);

        if ($organization->type === OrganizationType::Group) {
            $visiblePath = $organization->path;
            $includeDescendants = $membership->access_scope === AccessScope::Descendants;
            $writablePath = $organization->path;
            $writeIncludesDescendants = false;
        } else {
            $scopeRoot = $company ?? $organization;
            $visiblePath = $writablePath = $scopeRoot->path;
            $includeDescendants = $writeIncludesDescendants = true;
        }

        $this->context->enterOrganization(
            user: $user,
            membership: $membership,
            ancestors: $ancestors,
            company: $company,
            group: $group,
            visiblePath: $visiblePath,
            includeDescendants: $includeDescendants,
            writablePath: $writablePath,
            writeIncludesDescendants: $writeIncludesDescendants,
            settings: $this->settings->values($organization, $ancestors),
        );

        return $this->context;
    }

    public function enterPartner(User $user, string $partnerId): CurrentContext
    {
        $partnerUser = PartnerUser::query()
            ->with('partner')
            ->where('user_id', $user->getKey())
            ->where('partner_id', $partnerId)
            ->first();

        if ($partnerUser === null || ! $partnerUser->isActive()) {
            throw OrganizationAccessDenied::noPartnerAccess();
        }

        if (! $partnerUser->partner->isActive()) {
            throw OrganizationAccessDenied::partnerInactive();
        }

        $this->context->enterPartner($user, $partnerUser);

        return $this->context;
    }
}
