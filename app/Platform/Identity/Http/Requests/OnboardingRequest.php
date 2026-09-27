<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Countries\CountryCatalog;
use App\Platform\Packaging\SectorCatalog;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

class OnboardingRequest extends StrictFormRequest
{
    use IdentityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locale' => $this->localeRule(true),
            // Countries are data files (database/data/countries).
            'country_code' => ['sometimes', 'nullable', 'string', Rule::in(app(CountryCatalog::class)->codes())],
            'sector_key' => ['nullable', 'string', Rule::in(app(SectorCatalog::class)->keys())],
        ];
    }
}
