<?php

namespace App\Platform\Billing\Http;

use App\Platform\Billing\Services\WholesalePriceBook;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\Models\PartnerPlanPrice;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Packaging\PlanDefinition;
use App\Platform\Packaging\Services\PartnerPlanService;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;

/**
 * A partner's plans with what they cost the partner: our wholesale price
 * (wholesale partners) or the partner's revenue share (revenue-share
 * partners), so the console can show the margin.
 */
class PartnerPlanPresenter
{
    public function __construct(
        private PlanCatalog $plans,
        private ModuleRegistry $registry,
        private PartnerPlanService $partnerPlans,
        private WholesalePriceBook $wholesale,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(PartnerPlan $plan, ?int $clients = null): array
    {
        $plan->loadMissing('prices', 'partner');
        $locale = app()->getLocale();
        $selectable = $this->partnerPlans->selectableModules($plan->partner, $plan->base_plan_key);

        return [
            'id' => $plan->getKey(),
            'name' => $plan->label(),
            'name_texts' => $plan->name,
            'description' => $plan->description === null ? null : ($plan->description[$locale] ?? $plan->description['en'] ?? null),
            'description_texts' => $plan->description,
            'base_plan' => ['key' => $plan->base_plan_key, 'name' => $this->plans->get($plan->base_plan_key)->label()],
            // null = everything the base plan has.
            'modules' => $plan->modules,
            'included_modules' => $this->named($plan->modules ?? $selectable),
            'prices' => $plan->prices->sortBy(['currency_code', 'period'])->map(fn (PartnerPlanPrice $price) => [
                'currency' => $price->currency_code,
                'period' => $price->period,
                'amount_minor' => $price->amount_minor,
            ])->values(),
            'status' => $plan->status,
            'clients' => $clients ?? $this->partnerPlans->clientCount($plan),
            'cost' => $this->cost($plan->partner, $plan->base_plan_key),
        ];
    }

    /**
     * Our plans a partner plan can be built on, with the modules it may pick.
     *
     * @return list<array<string, mixed>>
     */
    public function basePlans(Partner $partner): array
    {
        return array_values(array_map(fn (PlanDefinition $plan) => [
            'key' => $plan->key,
            'name' => $plan->label(),
            'list_prices' => $plan->prices,
            // With what each needs, so the console can keep a plan's modules workable as they are picked.
            'modules' => array_map(fn (array $module) => [
                ...$module,
                'requires' => array_values(array_filter($this->registry->get($module['key'])->requires, fn (string $key) => ! $this->registry->get($key)->isCore)),
            ], $this->named($this->partnerPlans->selectableModules($partner, $plan->key))),
            'cost' => $this->cost($partner, $plan->key),
        ], array_filter($this->plans->all(), fn (PlanDefinition $plan) => $plan->public)));
    }

    /**
     * What a client on this base plan costs the partner.
     *
     * @return array<string, mixed>|null
     */
    public function cost(Partner $partner, string $basePlan): ?array
    {
        $context = $this->contexts->forPartner($partner);

        if ($partner->billing_mode === BillingMode::RevenueShare) {
            return ['kind' => 'revenue_share', 'share_bp' => (int) $this->rules->get('partners.revenue_share_bp', $context)];
        }

        if ($partner->billing_mode !== BillingMode::Wholesale) {
            return null;
        }

        $currency = (string) $this->rules->get('billing.partner_currency', $context);
        $price = $this->wholesale->find($partner, $basePlan, $currency, CarbonImmutable::today());

        return $price === null ? ['kind' => 'wholesale', 'currency' => $currency, 'unit' => null, 'amount_minor' => null] : [
            'kind' => 'wholesale',
            'currency' => $price->currency_code,
            'unit' => $price->unit,
            'amount_minor' => $price->amount_minor,
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key: string, name: string}>
     */
    private function named(array $keys): array
    {
        return array_values(array_map(
            fn (string $key) => ['key' => $key, 'name' => $this->registry->get($key)->label()],
            array_filter($keys, fn (string $key) => $this->registry->has($key)),
        ));
    }
}
