<?php

namespace App\Platform\Tenancy\Services;

use App\Platform\Countries\CountryCatalog;
use App\Platform\Countries\CountryDefinition;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Effective country / locale / timezone / currency / region for an
 * organization. A null column means "inherit": the nearest ancestor that
 * sets the value wins, then the organization's country (Phase 6: language,
 * timezone, currency and data region are country facts), then the platform
 * default.
 */
class OrganizationSettingsResolver
{
    public const INHERITABLE = ['country_code', 'default_locale', 'timezone', 'currency_code', 'region', 'plan_key'];

    public function __construct(private HierarchyService $hierarchy, private CountryCatalog $countries) {}

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
            $fromCountry = $source === null ? $this->countryValue($key, $resolved['country_code']['value'] ?? null) : null;

            $resolved[$key] = [
                'value' => $source?->{$key} ?? $fromCountry ?? config('tenancy.defaults.'.$key),
                'source' => match (true) {
                    $source === null && $fromCountry !== null => 'country',
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

    private function countryValue(string $key, ?string $countryCode): ?string
    {
        $country = $this->countries->find($countryCode);

        return $country === null ? null : match ($key) {
            'default_locale' => $this->usableLocale($country),
            'timezone' => $country->timezone,
            'currency_code' => $country->currency,
            'region' => $country->dataResidencyRegion ?: null,
            default => null,
        };
    }

    /** The country's first language the app speaks (UI texts exist for it). */
    private function usableLocale(CountryDefinition $country): ?string
    {
        $supported = (array) config('tenancy.supported_locales');
        $candidates = array_values(array_unique([$country->defaultLocale, ...$country->locales]));

        foreach ($candidates as $locale) {
            if (in_array($locale, $supported, true)) {
                return $locale;
            }
        }

        return null;
    }
}
