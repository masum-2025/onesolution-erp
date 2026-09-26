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
     * @return BelongsTo<PartnerPlan, $this>
     */
    public function partnerPlan(): BelongsTo
    {
        return $this->belongsTo(PartnerPlan::class);
    }
}
