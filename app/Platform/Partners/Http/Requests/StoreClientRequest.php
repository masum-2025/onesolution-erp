<?php

namespace App\Platform\Partners\Http\Requests;

use App\Platform\Packaging\PlanCatalog;
use App\Platform\Packaging\SectorCatalog;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $locales = (array) config('tenancy.supported_locales');

        return [
            'name' => ['required', 'array:'.implode(',', $locales)],
            'name.en' => ['required', 'string', 'max:150'],
            'name.bn' => ['nullable', 'string', 'max:150'],
            'sector_key' => ['required', 'string', Rule::in(app(SectorCatalog::class)->keys())],
            'plan' => ['required', 'string', Rule::in(app(PlanCatalog::class)->keys(PlanCatalog::BUSINESS))],
            // The client's first owner. Without an account yet, give a name: they are invited by email.
            'owner_email' => ['required', 'string', 'email', 'max:255'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:120'],
            'country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'default_locale' => ['sometimes', 'nullable', 'string', Rule::in($locales)],
        ];
    }
}
