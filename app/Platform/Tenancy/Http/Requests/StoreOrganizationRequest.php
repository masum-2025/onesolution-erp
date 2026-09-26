<?php

namespace App\Platform\Tenancy\Http\Requests;

use App\Platform\Packaging\SectorCatalog;
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
            // Root groups (partner / platform) and personal workspaces (sign-up) are never made from a client context.
            'type' => ['required', Rule::enum(OrganizationType::class)->except([OrganizationType::Group, OrganizationType::Personal])],
            ...$this->organizationAttributeRules(partial: false),
            'sector_key' => ['required_if:type,company', 'nullable', 'string', Rule::in(app(SectorCatalog::class)->keys())],
        ];
    }
}
