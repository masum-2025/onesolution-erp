<?php

namespace App\Platform\Portal\Models;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A portal member may see one record (their child, their own employee
 * record...) once the client approved it. Only active links count.
 * Nothing is mass assignable.
 *
 * Not tenant-scoped on purpose: made while joining, before any context;
 * staff and members read it filtered by the context's organization and
 * membership.
 */
#[Table('portal_links')]
class PortalLink extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const REJECTED = 'rejected';

    public const REVOKED = 'revoked';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<OrganizationMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
