<?php

namespace App\Platform\Tenancy\Models;

use App\Models\User;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's own staff member. Gives access to the partner console only,
 * never to a client organization's business data.
 */
#[Fillable(['partner_id', 'user_id', 'role', 'status', 'invited_by'])]
class PartnerUser extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'role' => PartnerUserRole::class,
            'status' => MembershipStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
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
        return $this->status === MembershipStatus::Active;
    }
}
