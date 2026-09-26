<?php

namespace App\Platform\Notifications\Services;

use App\Models\User;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Models\PartnerUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who should hear about something: the people who can act on it. For an
 * organization, active owners there or above, and active staff whose roles
 * there or above hold the permission (the same reach the access rules use).
 * For a partner, its active staff in the given roles. Portal users never.
 */
class Recipients
{
    /**
     * @return Collection<int, User>
     */
    public function holding(string $permission, Organization $organization): Collection
    {
        $levels = [...$organization->ancestorIds(), $organization->getKey()];

        $memberships = DB::table('organization_user')
            ->whereIn('organization_id', $levels)
            ->where('status', MembershipStatus::Active->value)
            ->where(fn ($query) => $query
                ->where('membership_type', MembershipType::Owner->value)
                ->orWhere(fn ($staff) => $staff
                    ->where('membership_type', MembershipType::Staff->value)
                    ->whereIn('id', DB::table('membership_roles')
                        ->join('role_permissions', 'role_permissions.role_id', '=', 'membership_roles.role_id')
                        ->where('role_permissions.permission_key', $permission)
                        ->select('membership_roles.membership_id'))));

        return User::query()->whereIn('id', $memberships->select('user_id'))->orderBy('id')->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function partnerStaff(Partner $partner, PartnerUserRole ...$roles): Collection
    {
        return User::query()
            ->whereIn('id', PartnerUser::query()
                ->where('partner_id', $partner->getKey())
                ->where('status', MembershipStatus::Active)
                ->whereIn('role', array_map(fn (PartnerUserRole $role) => $role->value, $roles))
                ->select('user_id'))
            ->orderBy('id')
            ->get();
    }
}
