<?php

namespace App\Platform\Tenancy\Http\Requests;

use App\Platform\Packaging\SectorCatalog;
use Illuminate\Validation\Rule;

/**
 * Validation for the organization fields a client may set. Tree fields are
 * never accepted here.
 */
trait OrganizationAttributeRules
{
    /**
     * @return array<string, mixed>
     */
    protected function organizationAttributeRules(bool $partial): array
    {
        $locales = config('tenancy.supported_locales');
        $fallback = config('app.fallback_locale');
        $presence = $partial ? 'sometimes' : 'required';

        $rules = [
            'name' => [$presence, 'array', 'array:'.implode(',', $locales)],
            // Sectors are the sector packages (data): a new sector needs no code.
            'sector_key' => ['sometimes', 'nullable', 'string', Rule::in(app(SectorCatalog::class)->keys())],
            'country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'default_locale' => ['sometimes', 'nullable', 'string', Rule::in($locales)],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'region' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9-]{2,10}$/'],
        ];

        foreach ($locales as $locale) {
            $rules['name.'.$locale] = [
                $locale === $fallback ? 'required_with:name' : 'nullable',
                'string',
                'max:150',
            ];
        }

        return $rules;
    }
}
