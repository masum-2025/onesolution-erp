<?php

namespace App\Platform\Packaging\Http;

use App\Platform\Modules\ModuleRegistry;
use App\Platform\Packaging\PlanDefinition;
use App\Platform\Packaging\SectorPackageDefinition;
use App\Platform\Packaging\Services\UsageLimiter;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;

/**
 * Shapes plans and sector packages for the app: translated names, prices
 * (integer minor units), limits and included modules with their names.
 */
class PlanPresenter
{
    public function __construct(
        private ModuleRegistry $registry,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(PlanDefinition $plan): array
    {
        $context = $this->contexts->forPlan($plan->key);

        return [
            'key' => $plan->key,
            'name' => $plan->label(),
            'description' => $plan->description(),
            'prices' => array_map(fn (array $price) => [
                'currency' => $price['currency'],
                'period' => $price['period'],
                'amount_minor' => $price['amount_minor'],
            ], $plan->prices),
            // Plan-level values (platform defaults where the plan sets none).
            'limits' => array_map(fn (string $rule) => $this->rules->get($rule, $context), UsageLimiter::LIMITS),
            'modules' => $this->modules(array_values(array_filter(
                $this->registry->keys(),
                fn (string $key) => $plan->includes($key) && $this->registry->get($key)->allowsPlan($plan->key) && ! $this->registry->get($key)->isCore,
            ))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sector(SectorPackageDefinition $package): array
    {
        return [
            'key' => $package->key,
            'name' => $package->label(),
            'description' => $package->description(),
            'modules' => $this->modules(array_values(array_filter($package->modules, fn (string $key) => $this->registry->has($key)))),
            'roles' => array_map(fn (string $template) => __("access.templates.{$template}.name"), $package->roleTemplates),
            'rules_count' => count($package->rules),
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key: string, name: string}>
     */
    public function modules(array $keys): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'name' => $this->registry->get($key)->label()], $keys);
    }
}
