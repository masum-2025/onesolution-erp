<?php

namespace App\Platform\Packaging\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Packaging\Exceptions\PackagingException;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;

/**
 * A partner's own plans. A partner plan can only take modules away from its
 * base plan (and only offer what the platform lets the partner offer). While
 * clients are on it, its base plan and modules are fixed: make a new plan
 * instead. Name and prices can always change (prices apply from the next
 * invoice). Archiving hides it from new clients; existing clients keep it.
 */
class PartnerPlanService
{
    public function __construct(
        private PlanCatalog $plans,
        private ModuleRegistry $registry,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{base_plan_key: string, name: array<string, string>, description?: array<string, string>|null, modules?: list<string>|null, prices: list<array{currency: string, period: string, amount_minor: int}>}  $data
     */
    public function create(Partner $partner, array $data, User $actor): PartnerPlan
    {
        $modules = $this->checkedModules($partner, $data['base_plan_key'], $data['modules'] ?? null);

        return DB::transaction(function () use ($partner, $data, $modules, $actor) {
            $plan = new PartnerPlan([
                'base_plan_key' => $data['base_plan_key'],
                'name' => $this->texts($data['name']),
                'description' => isset($data['description']) ? $this->texts($data['description']) : null,
                'modules' => $modules,
                'status' => PartnerPlan::ACTIVE,
            ]);
            $plan->forceFill(['partner_id' => $partner->getKey(), 'created_by' => $actor->getKey()])->save();
            $this->replacePrices($plan, $data['prices']);

            $this->audit->record(
                action: 'partner_plan.created',
                target: $plan,
                new: ['base_plan' => $plan->base_plan_key, 'name' => $plan->name, 'modules' => $modules, 'prices' => $data['prices']],
                actor: $actor,
                partnerId: $partner->getKey(),
            );

            return $plan->load('prices');
        });
    }

    /**
     * @param  array<string, mixed>  $changes  Any of name, description, base_plan_key, modules, prices.
     */
    public function update(PartnerPlan $plan, array $changes, User $actor): PartnerPlan
    {
        return DB::transaction(function () use ($plan, $changes, $actor) {
            $plan = PartnerPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $old = ['name' => $plan->name, 'base_plan' => $plan->base_plan_key, 'modules' => $plan->modules, 'prices' => $this->pricesOf($plan)];

            $packagingChanges = array_key_exists('base_plan_key', $changes) || array_key_exists('modules', $changes);
            if ($packagingChanges && $this->clientCount($plan) > 0) {
                throw PackagingException::planInUse();
            }

            if ($packagingChanges) {
                $base = $changes['base_plan_key'] ?? $plan->base_plan_key;
                $plan->base_plan_key = $base;
                $plan->modules = $this->checkedModules($plan->partner, $base, array_key_exists('modules', $changes) ? $changes['modules'] : $plan->modules);
            }

            if (array_key_exists('name', $changes)) {
                $plan->name = $this->texts($changes['name']);
            }

            if (array_key_exists('description', $changes)) {
                $plan->description = $changes['description'] === null ? null : $this->texts($changes['description']);
            }

            $plan->save();

            if (array_key_exists('prices', $changes)) {
                $this->replacePrices($plan, $changes['prices']);
            }

            $this->audit->record(
                action: 'partner_plan.updated',
                target: $plan,
                old: $old,
                new: ['name' => $plan->name, 'base_plan' => $plan->base_plan_key, 'modules' => $plan->modules, 'prices' => $this->pricesOf($plan)],
                actor: $actor,
                partnerId: $plan->partner_id,
            );

            return $plan->load('prices');
        });
    }

    public function archive(PartnerPlan $plan, string $reason, User $actor): PartnerPlan
    {
        $plan->forceFill(['status' => PartnerPlan::ARCHIVED])->save();

        $this->audit->record(
            action: 'partner_plan.archived',
            target: $plan,
            old: ['status' => PartnerPlan::ACTIVE],
            new: ['status' => PartnerPlan::ARCHIVED],
            reason: $reason,
            actor: $actor,
            partnerId: $plan->partner_id,
        );

        return $plan;
    }

    public function clientCount(PartnerPlan $plan): int
    {
        return Subscription::query()->where('partner_plan_id', $plan->getKey())->count();
    }

    /**
     * Modules a partner may put in a plan on this base plan: in the base plan,
     * allowing it, not core (always on), and offered by the partner.
     *
     * @return list<string>
     */
    public function selectableModules(Partner $partner, string $basePlan): array
    {
        $offered = $this->rules->get('partners.allowed_modules', $this->contexts->forPartner($partner));

        return array_values(array_filter($this->registry->keys(), function (string $key) use ($basePlan, $offered) {
            $module = $this->registry->get($key);

            return ! $module->isCore
                && $this->plans->includes($basePlan, $key)
                && $module->allowsPlan($basePlan)
                && (! is_array($offered) || in_array($key, $offered, true));
        }));
    }

    /**
     * @param  list<string>|null  $modules
     * @return list<string>|null
     */
    private function checkedModules(Partner $partner, string $basePlan, ?array $modules): ?array
    {
        if (! $this->plans->has($basePlan)) {
            throw PackagingException::unknownPlan();
        }

        if ($modules === null) {
            return null;
        }

        $allowed = $this->selectableModules($partner, $basePlan);
        foreach ($modules as $module) {
            if (! in_array($module, $allowed, true)) {
                throw PackagingException::moduleNotInBase($module);
            }
        }

        $modules = array_values(array_unique($modules));
        sort($modules);

        // A module without what it needs could never turn on.
        foreach ($modules as $module) {
            foreach ($this->registry->get($module)->requires as $required) {
                if (! $this->registry->get($required)->isCore && ! in_array($required, $modules, true)) {
                    throw PackagingException::moduleNeeds($this->registry->get($module)->label(), $this->registry->get($required)->label());
                }
            }
        }

        return $modules;
    }

    /**
     * @param  list<array{currency: string, period: string, amount_minor: int}>  $prices
     */
    private function replacePrices(PartnerPlan $plan, array $prices): void
    {
        $plan->prices()->delete();

        foreach ($prices as $price) {
            $plan->prices()->create([
                'currency_code' => $price['currency'],
                'period' => $price['period'],
                'amount_minor' => $price['amount_minor'],
            ]);
        }
    }

    /**
     * @return list<array{currency: string, period: string, amount_minor: int}>
     */
    private function pricesOf(PartnerPlan $plan): array
    {
        return $plan->prices()->orderBy('currency_code')->orderBy('period')->get()
            ->map(fn ($price) => ['currency' => $price->currency_code, 'period' => $price->period, 'amount_minor' => $price->amount_minor])
            ->all();
    }

    /**
     * @param  array<string, string|null>  $texts
     * @return array<string, string>
     */
    private function texts(array $texts): array
    {
        return array_filter(array_map(fn ($text) => $text === null ? null : trim($text), $texts), fn ($text) => $text !== null && $text !== '');
    }
}
