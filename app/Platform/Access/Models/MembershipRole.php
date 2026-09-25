<?php

namespace App\Platform\Access\Models;

use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role held by one membership. organization_id is the membership's
 * organization. Written only by RoleService (checks scope, escalation and
 * separation of duties).
 */
#[Table('membership_roles')]
#[Fillable(['organization_id', 'membership_id', 'role_id', 'assigned_by'])]
class MembershipRole extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<OrganizationMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'membership_id');
    }
}
