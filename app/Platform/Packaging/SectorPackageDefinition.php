<?php

namespace App\Platform\Packaging;

/**
 * What a new company of one sector starts with
 * (database/seeders/data/sector-packages.php).
 */
final readonly class SectorPackageDefinition
{
    /**
     * @param  list<string>  $modules
     * @param  list<array{key: string, value: mixed, mode?: string}>  $rules
     * @param  list<string>  $roleTemplates
     */
    public function __construct(
        public string $key,
        public array $modules,
        public array $rules,
        public array $roleTemplates,
        public ?string $demoSeeder,
        public int $sortOrder,
    ) {}

    /**
     * Changes whenever the package content changes, so a re-applied package is recognisable.
     */
    public function version(): string
    {
        return substr(hash('sha256', json_encode([$this->modules, $this->rules, $this->roleTemplates])), 0, 12);
    }

    public function label(?string $locale = null): string
    {
        $key = "packaging.sectors.{$this->key}.name";
        $text = __($key, [], $locale);

        return $text === $key ? $this->key : $text;
    }

    public function description(?string $locale = null): string
    {
        return __("packaging.sectors.{$this->key}.description", [], $locale);
    }
}
