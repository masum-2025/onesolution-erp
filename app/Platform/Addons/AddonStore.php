<?php

namespace App\Platform\Addons;

use App\Platform\Addons\Models\OrganizationAddon;
use App\Platform\Modules\ModuleDefinition;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Modules\ResolvedModule;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;

/**
 * The add-on store as one account sees it: every module the platform
 * deploys, with whether it is in the plan, running as an add-on, for sale
 * (and at what price), or not available here, and what it needs first.
 */
class AddonStore
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleResolver $resolver,
        private Addons $addons,
        private AddonPriceBook $prices,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forAccount(Organization $root): array
    {
        $resolved = $this->resolver->fresh($root);
        $rows = OrganizationAddon::query()->where('organization_id', $root->getKey())->get()->keyBy('module_key');
        $billsClient = $this->addons->billsClient($root);
        $currency = $this->addons->currency($root);
        $trialDays = (int) $this->addons->rule('billing.addon_trial_days', $root);
        $partnerCurrency = $billsClient ? null : (string) $this->rules->get('billing.partner_currency', $this->contexts->forPartner($root->partner));

        $items = [];
        foreach ($this->registry->all() as $key => $module) {
            if ($module->isCore) {
                continue;
            }

            $state = $resolved[$key];
            $row = $rows->get($key);
            $running = $row?->isRunning() ?? false;
            $price = $this->prices->listPrice($root->partner, $key, $currency);
            $priced = $billsClient ? $price !== null : $this->prices->wholesalePrice($root->partner, $key, $partnerCurrency) !== null;

            $status = match (true) {
                $running => $row->status,
                $state->available => 'in_plan',
                ! $module->sellable || in_array($state->reason->value, ['not_offered', 'sector_not_allowed'], true) => 'unavailable',
                ! $priced => 'unavailable',
                default => 'for_sale',
            };

            $items[] = [
                'key' => $key,
                'name' => $module->label(),
                'description' => __($module->description),
                'category' => $module->category,
                'status' => $status,
                'enabled' => $state->enabled,
                // Wholesale partners bill their own clients: the price shown is theirs, when they set one.
                'price' => $price === null ? null : ['amount_minor' => $price, 'currency' => $currency],
                'billed_by_provider' => ! $billsClient,
                'can_trial' => $status === 'for_sale' && $trialDays > 0 && ! ($row?->trial_used ?? false),
                'trial_ends_at' => $row?->status === OrganizationAddon::TRIAL ? $row->trial_ends_at?->toIso8601String() : null,
                'period_end' => $row?->status === OrganizationAddon::ACTIVE ? $row->period_end?->toDateString() : null,
                'cancel_at_period_end' => (bool) $row?->cancel_at_period_end,
                'requires' => $this->requires($module, $resolved),
            ];
        }

        return [
            'open' => (bool) $this->addons->rule('billing.addons_enabled', $root),
            'self_purchase' => (bool) $this->addons->rule('billing.addon_self_purchase', $root),
            'trial_days' => $trialDays,
            'currency' => $currency,
            'provider' => $root->partner->is_house ? null : $root->partner->name,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, ResolvedModule>  $resolved
     * @return list<array{key: string, name: string, available: bool}>
     */
    private function requires(ModuleDefinition $module, array $resolved): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'name' => $this->registry->get($key)->label(),
            'available' => $resolved[$key]->available,
        ], $module->requires);
    }
}
