<?php

namespace App\Platform\Packaging\Http;

use App\Platform\Modules\ModuleRegistry;
use App\Platform\Packaging\Models\OrganizationPackage;
use App\Platform\Packaging\SectorCatalog;
use App\Platform\Rules\RuleCatalog;

/**
 * What a sector package did, with names in the user's language.
 */
class PackageSummary
{
    public function __construct(
        private SectorCatalog $sectors,
        private ModuleRegistry $registry,
        private RuleCatalog $rules,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(OrganizationPackage $record): array
    {
        $summary = $record->summary;
        $module = fn (string $key) => ['key' => $key, 'name' => $this->registry->has($key) ? $this->registry->get($key)->label() : $key];

        return [
            'package' => [
                'key' => $record->package_key,
                'name' => $this->sectors->has($record->package_key) ? $this->sectors->get($record->package_key)->label() : $record->package_key,
            ],
            'applied_at' => $record->created_at?->toIso8601String(),
            'modules_enabled' => array_map($module, $summary['modules_enabled'] ?? []),
            'modules_upgrade' => array_map($module, $summary['modules_upgrade'] ?? []),
            'rules_set' => array_map(
                fn (string $key) => ['key' => $key, 'name' => $this->rules->has($key) ? $this->rules->get($key)->label() : $key],
                $summary['rules_set'] ?? [],
            ),
            'roles_created' => array_map(
                fn (string $key) => ['key' => $key, 'name' => __("access.templates.{$key}.name")],
                $summary['roles_created'] ?? [],
            ),
            'skipped' => [
                ...($summary['modules_skipped'] ?? []),
                ...($summary['rules_skipped'] ?? []),
                ...($summary['roles_skipped'] ?? []),
            ],
        ];
    }
}
