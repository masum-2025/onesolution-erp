<?php

namespace App\Platform\SupportAccess\Models;

use App\Models\User;
use App\Platform\SupportAccess\Enums\GrantStatus;
use App\Platform\SupportAccess\Enums\Severity;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Break-glass access of one partner staff member to one client organization:
 * requested with a reason, approved by the client (or its auto-approval
 * rule), read-only, time-limited. Written only by SupportAccessService.
 */
#[Table('support_grants')]
#[Fillable([
    'partner_id', 'organization_id', 'requested_by', 'reason', 'severity', 'access', 'duration_minutes',
    'status', 'auto_approved', 'decided_by', 'decided_at', 'decision_reason', 'starts_at', 'expires_at', 'ended_at',
])]
class SupportGrant extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'status' => GrantStatus::class,
            'severity' => Severity::class,
            'auto_approved' => 'boolean',
            'duration_minutes' => 'integer',
            'decided_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
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
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Approved and inside its time window right now. */
    public function isUsable(): bool
    {
        return $this->status === GrantStatus::Approved
            && $this->starts_at !== null && $this->starts_at->lte(now())
            && $this->expires_at !== null && $this->expires_at->gt(now());
    }
}
