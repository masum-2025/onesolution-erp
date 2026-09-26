<?php

namespace App\Platform\Packaging;

/**
 * One plan as declared in database/seeders/data/plans.php.
 */
final readonly class PlanDefinition
{
    /**
     * @param  list<string>  $modules  Module keys, or ['*'] for every module.
     * @param  list<array{currency: string, period: string, amount_minor: int}>  $prices
     */
    public function __construct(
        public string $key,
        public bool $public,
        public array $modules,
        public array $prices,
        public int $sortOrder,
        // business: for organizations; personal: for self-serve individuals (Phase 5C).
        public string $audience = PlanCatalog::BUSINESS,
    ) {}

    public function includes(string $moduleKey): bool
    {
        return in_array('*', $this->modules, true) || in_array($moduleKey, $this->modules, true);
    }

    public function label(?string $locale = null): string
    {
        $key = "packaging.plans.{$this->key}.name";
        $text = __($key, [], $locale);

        return $text === $key ? $this->key : $text;
    }

    public function description(?string $locale = null): string
    {
        return __("packaging.plans.{$this->key}.description", [], $locale);
    }
}
