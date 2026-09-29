<?php

namespace App\Platform\Tenancy\Context;

use App\Models\User;
use App\Platform\Partners\HostContext;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\SupportAccess\Enums\GrantStatus;
use App\Platform\SupportAccess\Models\SupportGrant;
use App\Platform\Tenancy\Contracts\SignInRequirements;
use App\Platform\Tenancy\Contracts\WorkspaceRestrictions;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
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
 * Visibility (reading):
 *  - company / branch / department member: the whole company subtree.
 *    Changes reach only the member's own unit and below, and need a
 *    permission (AccessResolver, Phase 4);
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
        private HostContext $host,
        private RuleResolver $rules,
        private RuleContextFactory $ruleContexts,
        private WorkspaceRestrictions $restrictions,
        private SignInRequirements $signIn,
    ) {}

    /**
     * What the person must have set up to work there (two-step sign-in, Phase
     * 8-1) is checked on entry. Offline sync and machine keys pass
     * checkSignIn: false (a device hands over its changes whatever happens).
     */
    public function enterOrganization(User $user, string $organizationId, bool $checkSignIn = true): CurrentContext
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
        $partner = $organization->partner;

        // A suspended partner's clients keep reading and exporting for a grace
        // period, then may only export (Phase 5B-2); any other status blocks.
        if (! $partner->isActive() && ! $partner->isSuspended()) {
            throw OrganizationAccessDenied::partnerInactive();
        }

        $this->enter($user, $membership, $organization);
        if ($checkSignIn) {
            $this->signIn->check($user, $this->context);
        }

        if ($partner->isSuspended()) {
            $graceEnds = ($partner->suspended_at ?? now())->copy()->addDays(
                (int) $this->rules->get('partners.suspension_grace_days', $this->ruleContexts->forPartner($partner)),
            );

            $this->context->restrict(
                $graceEnds->isFuture() ? CurrentContext::MODE_READ_ONLY : CurrentContext::MODE_EXPORT_ONLY,
                'partner_suspended',
                $graceEnds->isFuture() ? $graceEnds : null,
            );

            return $this->context;
        }

        // The account itself may be limited, e.g. read-only while a bill is overdue (Phase 5C-2).
        $root = $this->context->ancestors()->first() ?? $organization;
        $reason = $this->restrictions->readOnlyReason($root);
        if ($reason !== null) {
            $this->context->restrict(CurrentContext::MODE_READ_ONLY, $reason);
        }

        return $this->context;
    }

    /**
     * Partner staff inside a client with an approved support grant: read-only,
     * no membership is created, and the grant is checked again on every
     * request (ended, expired or revoked grants stop working at once).
     */
    public function enterSupport(User $user, string $grantId): CurrentContext
    {
        $grant = SupportGrant::query()->with('organization.partner')->find($grantId);

        // Only the person who asked may use a grant.
        if ($grant === null || $grant->requested_by !== $user->getKey()) {
            throw OrganizationAccessDenied::noSupportAccess();
        }

        // Never approved: no access. Approved once but over now: access ended.
        if (in_array($grant->status, [GrantStatus::Pending, GrantStatus::Rejected], true)) {
            throw OrganizationAccessDenied::noSupportAccess();
        }

        if (! $grant->isUsable()) {
            throw OrganizationAccessDenied::supportEnded();
        }

        $staff = PartnerUser::query()
            ->where('user_id', $user->getKey())
            ->where('partner_id', $grant->partner_id)
            ->first();

        if ($staff === null || ! $staff->isActive() || ! $grant->organization->partner->isActive()) {
            throw OrganizationAccessDenied::noSupportAccess();
        }

        $membership = new OrganizationMembership([
            'organization_id' => $grant->organization_id,
            'user_id' => $user->getKey(),
            'membership_type' => MembershipType::Staff,
            'access_scope' => AccessScope::Descendants,
            'status' => MembershipStatus::Active,
        ]);
        $membership->setRelation('organization', $grant->organization);
        $membership->setRelation('user', $user);

        $this->enter($user, $membership, $grant->organization, supportView: true);
        $this->signIn->check($user, $this->context);
        $this->context->restrict(CurrentContext::MODE_READ_ONLY, 'support', $grant->expires_at, $grant);

        return $this->context;
    }

    private function enter(User $user, OrganizationMembership $membership, Organization $organization, bool $supportView = false): void
    {
        // On a partner's (or client's) own domain, only that account's organizations open.
        if (! $this->host->allowsOrganization($organization)) {
            throw OrganizationAccessDenied::wrongAddress();
        }

        $ancestors = $this->hierarchy->ancestors($organization);

        // A suspended or archived organization also blocks everything below it.
        if (! $organization->isActive() || $ancestors->contains(fn (Organization $node) => ! $node->isActive())) {
            throw OrganizationAccessDenied::organizationInactive();
        }

        $chain = $ancestors->concat([$organization]);
        $company = $chain->reverse()->first(fn (Organization $node) => $node->type->isCompanyLike());
        $group = $chain->first(fn (Organization $node) => $node->type === OrganizationType::Group);

        if ($supportView) {
            // Support sees exactly the granted organization and what is below it.
            $visiblePath = $writablePath = $organization->path;
            $includeDescendants = $writeIncludesDescendants = true;
        } elseif ($organization->type === OrganizationType::Group) {
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
    }

    public function enterPartner(User $user, string $partnerId, bool $checkSignIn = true): CurrentContext
    {
        $partnerUser = PartnerUser::query()
            ->with('partner')
            ->where('user_id', $user->getKey())
            ->where('partner_id', $partnerId)
            ->first();

        if ($partnerUser === null || ! $partnerUser->isActive()) {
            throw OrganizationAccessDenied::noPartnerAccess();
        }

        if (! $this->host->allowsPartner($partnerUser->partner_id)) {
            throw OrganizationAccessDenied::wrongAddress();
        }

        if (! $partnerUser->partner->isActive()) {
            throw OrganizationAccessDenied::partnerInactive();
        }

        $this->context->enterPartner($user, $partnerUser);
        if ($checkSignIn) {
            $this->signIn->check($user, $this->context);
        }

        return $this->context;
    }
}
