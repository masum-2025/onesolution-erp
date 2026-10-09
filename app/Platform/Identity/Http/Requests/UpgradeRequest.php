<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Packaging\SectorCatalog;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * Turning a personal workspace into a company: its name (per language),
 * sector and business plan.
 */
class UpgradeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $locales = LanguageRegistry::codes();
        $rules = [
            'name' => ['required', 'array', 'array:'.implode(',', $locales)],
            'sector_key' => ['nullable', 'string', Rule::in(app(SectorCatalog::class)->keys())],
            'plan_key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
            'period' => ['required', 'string', Rule::in(PlanCatalog::PERIODS)],
        ];

        foreach ($locales as $locale) {
            $rules['name.'.$locale] = [$locale === config('app.fallback_locale') ? 'required' : 'nullable', 'string', 'min:2', 'max:150'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function names(): array
    {
        return array_filter((array) $this->validated('name'), fn ($value) => is_string($value) && trim($value) !== '');
    }
}
