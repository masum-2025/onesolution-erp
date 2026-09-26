<?php

namespace App\Platform\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Billing\Http\PartnerPlanPresenter;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;

/**
 * Partner console: one client's subscription and the plans it can move to
 * (ours and the partner's active plans). Changing it: PartnerPlanController.
 */
class PartnerSubscriptionController extends Controller
{
    use PartnerConsole;

    public function __construct(private SubscriptionService $subscriptions, private PartnerPlanPresenter $presenter) {}

    public function show(string $client): JsonResponse
    {
        $root = $this->client($client);
        $partner = $this->partner();
        $subscription = $this->subscriptions->for($root);

        return response()->json(['data' => [
            'plan_key' => $this->subscriptions->planKey($root),
            'partner_plan_id' => $subscription->partner_plan_id,
            'plan_name' => $this->subscriptions->planName($subscription, $root),
            'currency' => $subscription->currency_code,
            'period' => $subscription->period,
            'price_minor' => $this->subscriptions->price($subscription, $root),
            'billed_through' => $subscription->billed_through?->toDateString(),
            'billing_mode' => $partner->billing_mode->value,
            'partner_plans' => PartnerPlan::query()
                ->where('partner_id', $partner->getKey())
                ->where(fn ($query) => $query->where('status', PartnerPlan::ACTIVE)->orWhereKey($subscription->partner_plan_id))
                ->with('prices')
                ->orderBy('created_at')
                ->get()
                ->map(fn (PartnerPlan $plan) => [
                    'id' => $plan->getKey(),
                    'name' => $plan->label(),
                    'base_plan_key' => $plan->base_plan_key,
                    'status' => $plan->status,
                    'prices' => $plan->prices->map(fn ($price) => ['currency' => $price->currency_code, 'period' => $price->period, 'amount_minor' => $price->amount_minor])->values(),
                ])->values(),
            'base_plans' => $this->presenter->basePlans($partner),
            'can_change' => $this->hasRole(PartnerUserRole::Owner, PartnerUserRole::Billing),
        ]]);
    }
}
