<?php

namespace App\Platform\Addons\Models;

use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A module an account (its top organization) has on top of its plan: on
 * trial, active (paid month by month from the day it was bought), or ended.
 * Ending only turns the module off; its data stays.
 *
 * Not tenant-scoped on purpose, like subscriptions: the daily cycle and the
 * billing run read across accounts; every read filters by organization.
 */
#[Table('organization_addons')]
// Nothing comes from a request: Addons sets every field itself (forceFill).
#[Fillable([])]
class OrganizationAddon extends Model
{
    use HasUlids;

    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    /** Statuses that make the module available. */
    public const RUNNING = [self::TRIAL, self::ACTIVE];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'trial_ends_at' => 'immutable_datetime',
            'trial_used' => 'boolean',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'cancel_at_period_end' => 'boolean',
            'ended_at' => 'immutable_datetime',
        ];
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
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
}
