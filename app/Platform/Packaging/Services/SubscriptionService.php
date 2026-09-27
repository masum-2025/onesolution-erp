<?php

namespace App\Platform\Packaging\Services;

use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A client's subscription and what it costs. Every top organization has one;
 * it is created the first time anything needs it, with the organization's
 * currency and a monthly period.
 */
class SubscriptionService
{
    public function __construct(private PlanCatalog $plans) {}

    public function for(Organization $root): Subscription
    {
        if (! $root->isRoot()) {
            throw new InvalidArgumentException('Only a top organization has a subscription.');
        }

        $existing = Subscription::query()->where('organization_id', $root->getKey())->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            // A savepoint, so a lost race does not break the caller's transaction.
            return DB::transaction(fn () => $this->create($root));
        } catch (UniqueConstraintViolationException) {
            return Subscription::query()->where('organization_id', $root->getKey())->firstOrFail();
        }
    }

    public function planKey(Organization $root): string
    {
        return $root->plan_key ?? config('tenancy.defaults.plan_key');
    }

    /**
     * What one period of the subscription costs in its currency: the partner
     * plan's price when it has one, else our plan's list price. null = no price
     * in that currency and period.
     */
    public function price(Subscription $subscription, Organization $root): ?int
    {
        if ($subscription->partner_plan_id !== null) {
            $plan = $subscription->partnerPlan()->with('prices')->first();

            return $plan?->price($subscription->currency_code, $subscription->period);
        }

        return $this->listPrice($this->planKey($root), $subscription->currency_code, $subscription->period);
    }

    public function listPrice(string $planKey, string $currency, string $period): ?int
    {
        if (! $this->plans->has($planKey)) {
            return null;
        }

        foreach ($this->plans->get($planKey)->prices as $price) {
            if ($price['currency'] === $currency && $price['period'] === $period) {
                return $price['amount_minor'];
            }
        }

        return null;
    }

    public function planName(Subscription $subscription, Organization $root): string
    {
        $partnerPlan = $subscription->partner_plan_id === null ? null : $subscription->partnerPlan;

        return $partnerPlan instanceof PartnerPlan ? $partnerPlan->label() : $this->plans->get($this->planKey($root))->label();
    }

    private function create(Organization $root): Subscription
    {
        $subscription = new Subscription([
            'currency_code' => $root->currency_code ?? config('tenancy.defaults.currency_code'),
            'period' => 'monthly',
            'status' => Subscription::ACTIVE,
        ]);
        $subscription->forceFill([
            'organization_id' => $root->getKey(),
            'partner_id' => $root->partner_id,
            'self_serve' => $root->type->isSelfServe(),
            'started_on' => ($root->created_at ?? now())->toDateString(),
        ])->save();

        return $subscription;
    }
}
