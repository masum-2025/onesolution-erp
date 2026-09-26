<?php

namespace App\Platform\Tenancy\Actions;

use App\Models\User;
use App\Platform\Partners\HostContext;
use App\Platform\Tenancy\Enums\MembershipStatus;

/**
 * The organizations and partner consoles a user may enter right now:
 * active memberships in active organizations / partners only, and on a
 * partner's own domain only that partner's.
 */
class ListAvailableContexts
{
    public function __construct(private HostContext $host) {}

    /**
     * @return array{organizations: list<array<string, mixed>>, partners: list<array<string, mixed>>}
     */
    public function handle(User $user): array
    {
        $organizations = $user->memberships()
            ->with('organization')
            ->where('status', MembershipStatus::Active)
            ->get()
            ->filter(fn ($membership) => $membership->organization->isActive() && $this->host->allowsOrganization($membership->organization))
            ->map(fn ($membership) => [
                'organization_id' => $membership->organization_id,
                'name' => $membership->organization->displayName(),
                'type' => $membership->organization->type->value,
                'membership_type' => $membership->membership_type->value,
                'is_primary' => $membership->is_primary,
            ])
            ->values()
            ->all();

        $partners = $user->partnerMemberships()
            ->with('partner')
            ->where('status', MembershipStatus::Active)
            ->get()
            ->filter(fn ($partnerUser) => $partnerUser->partner->isActive() && $this->host->allowsPartner($partnerUser->partner_id))
            ->map(fn ($partnerUser) => [
                'partner_id' => $partnerUser->partner_id,
                'name' => $partnerUser->partner->name,
                'role' => $partnerUser->role->value,
            ])
            ->values()
            ->all();

        return ['organizations' => $organizations, 'partners' => $partners];
    }
}
