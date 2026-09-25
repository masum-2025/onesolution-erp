<?php

namespace App\Platform\Access;

/**
 * One permission: its key ("payroll.approve"), the module it belongs to
 * ("core" for platform permissions) and how to label it.
 */
final readonly class PermissionDefinition
{
    /**
     * @param  array<string, string>  $labelReplace
     */
    public function __construct(
        public string $key,
        public string $moduleKey,
        public string $labelKey,
        public string $group,
        public array $labelReplace = [],
    ) {}

    public function isCore(): bool
    {
        return $this->moduleKey === PermissionCatalog::CORE_MODULE;
    }

    public function label(?string $locale = null): string
    {
        $replace = array_map(fn (string $value) => __($value, [], $locale), $this->labelReplace);
        $text = __($this->labelKey, $replace, $locale);

        return $text === $this->labelKey ? $this->key : $text;
    }
}
