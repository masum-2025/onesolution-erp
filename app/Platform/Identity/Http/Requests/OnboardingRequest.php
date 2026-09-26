<?php

namespace App\Platform\Identity\Http\Requests;

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
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'sector_key' => ['nullable', 'string', Rule::in(app(SectorCatalog::class)->keys())],
        ];
    }
}
