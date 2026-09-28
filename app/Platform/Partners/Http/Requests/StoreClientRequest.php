<?php

namespace App\Platform\Partners\Http\Requests;

use App\Platform\Countries\CountryCatalog;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Packaging\SectorCatalog;
use App\Platform\Partners\Services\PartnerClientService;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * A new company for a client (console and partner API):
 *
 * - structure "company" (default): a single company, no group;
 * - structure "group": a new group (group_name, else the company's name) with its first company;
 * - structure "existing_group": another company in a group already served (group_id);
 *   plan and billing are the group's, the owner is optional.
 *
 * Names are data labels in every supported language (English required);
 * branches are optional first branches of the company.
 */
class StoreClientRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $locales = (array) config('tenancy.supported_locales');
        $existing = $this->input('structure') === PartnerClientService::EXISTING_GROUP;

        return [
            'structure' => ['sometimes', Rule::in([PartnerClientService::STANDALONE, PartnerClientService::GROUP, PartnerClientService::EXISTING_GROUP])],
            ...$this->texts('name', $locales, true),
            ...($this->input('structure') === PartnerClientService::GROUP ? $this->texts('group_name', $locales, false) : []),
            'group_id' => [Rule::requiredIf($existing), 'prohibited_unless:structure,'.PartnerClientService::EXISTING_GROUP, 'nullable', 'string', 'ulid'],
            'branches' => ['sometimes', 'array', 'max:20'],
            'branches.*' => ['array:'.implode(',', $locales)],
            'branches.*.en' => ['required', 'string', 'min:2', 'max:150'],
            ...array_fill_keys(array_map(fn (string $locale) => "branches.*.{$locale}", array_diff($locales, ['en'])), ['nullable', 'string', 'max:150']),
            'sector_key' => ['required', 'string', Rule::in(app(SectorCatalog::class)->keys())],
            // In an existing group the plan is the group's.
            'plan' => $existing ? ['prohibited'] : ['required', 'string', Rule::in(app(PlanCatalog::class)->keys(PlanCatalog::BUSINESS))],
            // The client's first owner. Without an account yet, give a name: they are invited by email.
            'owner_email' => [$existing ? 'nullable' : 'required', 'string', 'email', 'max:255'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:120'],
            // Countries are data files (database/data/countries).
            'country_code' => ['sometimes', 'nullable', 'string', Rule::in(app(CountryCatalog::class)->codes())],
            'currency_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'default_locale' => ['sometimes', 'nullable', 'string', Rule::in($locales)],
        ];
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, list<string>>
     */
    private function texts(string $field, array $locales, bool $required): array
    {
        $rules = [$field => [$required ? 'required' : 'sometimes', 'array:'.implode(',', $locales)]];
        foreach ($locales as $locale) {
            $rules["{$field}.{$locale}"] = [$locale === 'en' ? "required_with:{$field}" : 'nullable', 'string', 'max:150'];
        }

        return $rules;
    }
}
