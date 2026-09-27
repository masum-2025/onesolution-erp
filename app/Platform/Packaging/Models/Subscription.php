<?php

namespace App\Platform\Packaging\Models;

use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's subscription: one per top organization. The plan itself stays
 * on organizations.plan_key (modules and limits read it); this adds the
 * partner plan, the currency and period it is billed in, and how far it has
 * been invoiced. Created on first need (SubscriptionService::for).
 *
 * Self-serve subscriptions (personal workspaces, Phase 5C-2) are bought and
 * renewed by the client on its own dates instead of the monthly run, and
 * carry their trial, overdue and read-only state here.
 *
 * Not tenant-scoped on purpose: the partner console and the billing run read
 * subscriptions across clients; every read filters by partner or organization.
 */
#[Table('subscriptions')]
#[Fillable(['currency_code', 'period', 'status'])]
class Subscription extends Model
{
    use HasUlids;

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'started_on' => 'immutable_date',
            'billed_through' => 'immutable_date',
            // Self-serve lifecycle (Phase 5C-2).
            'self_serve' => 'boolean',
            'trial_ends_at' => 'immutable_datetime',
            'trial_reminded' => 'boolean',
            'cancel_at_period_end' => 'boolean',
            'past_due_since' => 'immutable_datetime',
            'restricted_at' => 'immutable_datetime',
            'reminders_sent' => 'integer',
        ];
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null;
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
     * @return BelongsTo<PartnerPlan, $this>
     */
    public function partnerPlan(): BelongsTo
    {
        return $this->belongsTo(PartnerPlan::class);
    }
}
