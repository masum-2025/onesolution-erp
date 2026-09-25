<?php

namespace App\Platform\Tenancy\Models;

use App\Models\User;
use App\Platform\Access\Models\Role;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Links one identity (user) to one organization. A person has one login and
 * many memberships; data never crosses from one membership to another.
 */
#[Table('organization_user')]
#[Fillable([
    'organization_id', 'user_id', 'membership_type',
    'access_scope', 'is_primary', 'status', 'invited_by',
])]
class OrganizationMembership extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'membership_type' => MembershipType::class,
            'access_scope' => AccessScope::class,
            'status' => MembershipStatus::class,
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Roles held in this membership (Phase 4). Written only by RoleService.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'membership_roles', 'membership_id', 'role_id');
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function isOwner(): bool
    {
        return $this->membership_type === MembershipType::Owner;
    }
}
