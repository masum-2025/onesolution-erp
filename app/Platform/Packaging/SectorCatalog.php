<?php

namespace App\Platform\Packaging;

use InvalidArgumentException;
use LogicException;

/**
 * Every sector package. Its keys are the only valid sector keys.
 */
final class SectorCatalog
{
    /** @var array<string, SectorPackageDefinition> */
    private array $packages = [];

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function __construct(array $entries)
    {
        foreach (array_values($entries) as $order => $entry) {
            $key = $entry['key'] ?? null;

            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{1,49}$/', $key) || isset($this->packages[$key])) {
                throw new LogicException('Every sector package needs a unique key like "school".');
            }

            foreach ($entry['rules'] ?? [] as $rule) {
                if (! is_string($rule['key'] ?? null) || ! array_key_exists('value', $rule)) {
                    throw new LogicException("Sector package [{$key}] has a rule without key or value.");
                }
            }

            $this->packages[$key] = new SectorPackageDefinition(
                key: $key,
                modules: array_values($entry['modules'] ?? []),
                rules: array_values($entry['rules'] ?? []),
                roleTemplates: array_values($entry['role_templates'] ?? []),
                demoSeeder: $entry['demo_seeder'] ?? null,
                sortOrder: $order,
            );
        }
    }

    public static function fromDataFile(): self
    {
        return new self(require database_path('seeders/data/sector-packages.php'));
    }

    /**
     * @return array<string, SectorPackageDefinition>
     */
    public function all(): array
    {
        return $this->packages;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->packages);
    }

    public function has(?string $key): bool
    {
        return $key !== null && isset($this->packages[$key]);
    }

    public function get(string $key): SectorPackageDefinition
    {
        return $this->packages[$key] ?? throw new InvalidArgumentException("Unknown sector [{$key}].");
    }
}
