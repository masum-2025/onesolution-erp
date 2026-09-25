<?php

namespace App\Platform\Tenancy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends StrictFormRequest
{
    use OrganizationAttributeRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['required', 'string', 'ulid'],
            // Root groups are created by the partner / platform, not from a client context.
            'type' => ['required', Rule::enum(OrganizationType::class)->except([OrganizationType::Group])],
            ...$this->organizationAttributeRules(partial: false),
            'sector_key' => ['required_if:type,company', 'nullable', 'string', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
        ];
    }
}
