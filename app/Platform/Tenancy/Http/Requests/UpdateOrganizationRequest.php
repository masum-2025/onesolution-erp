<?php

namespace App\Platform\Tenancy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends StrictFormRequest
{
    use OrganizationAttributeRules;

    /** Fields that define ownership or tree position: changed only by move/transfer. */
    private const PROTECTED_FIELDS = [
        'id', 'organization_id', 'partner_id', 'parent_id', 'root_id', 'path', 'depth', 'type', 'version',
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->organizationAttributeRules(partial: true),
            'status' => ['sometimes', Rule::enum(OrganizationStatus::class)],
            ...array_fill_keys(self::PROTECTED_FIELDS, ['prohibited']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (self::PROTECTED_FIELDS as $field) {
            $messages[$field.'.prohibited'] = __('tenancy.validation.use_move', ['field' => $field]);
        }

        return $messages;
    }
}
