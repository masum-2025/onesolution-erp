<?php

namespace App\Platform\Tenancy\Services;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Effective country / locale / timezone / currency / region for an
 * organization. A null column means "inherit": the nearest ancestor that
 * sets the value wins, then the platform default.
 */
class OrganizationSettingsResolver
{
    public const INHERITABLE = ['country_code', 'default_locale', 'timezone', 'currency_code', 'region'];

    public function __construct(private HierarchyService $hierarchy) {}

    /**
     * Values plus where each one comes from, for "inherited from X" in the UI.
     *
     * @param  Collection<int, Organization>|null  $ancestors  Root first; loaded when null.
     * @return array<string, array{value: string|null, source: string, source_organization_id: string|null, source_organization_name: string|null}>
     */
    public function explain(Organization $organization, ?Collection $ancestors = null): array
    {
        $chain = ($ancestors ?? $this->hierarchy->ancestors($organization))
            ->reverse()
            ->prepend($organization)
            ->values();

        $resolved = [];

        foreach (self::INHERITABLE as $key) {
            $source = $chain->first(fn (Organization $node) => $node->{$key} !== null);

            $resolved[$key] = [
                'value' => $source?->{$key} ?? config('tenancy.defaults.'.$key),
                'source' => match (true) {
                    $source === null => 'platform_default',
                    $source->is($organization) => 'self',
                    default => 'inherited',
                },
                'source_organization_id' => $source?->getKey(),
                'source_organization_name' => $source?->displayName(),
            ];
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, Organization>|null  $ancestors
     * @return array<string, string|null>
     */
    public function values(Organization $organization, ?Collection $ancestors = null): array
    {
        return array_map(fn (array $entry) => $entry['value'], $this->explain($organization, $ancestors));
    }
}
