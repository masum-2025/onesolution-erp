<?php

namespace App\Platform\Access\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class StoreRoleRequest extends StrictFormRequest
{
    use RoleAttributeRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->roleRules(creating: true),
            // Clone a sector template; without "permissions" its grantable permissions are used.
            'template_key' => ['sometimes', 'nullable', 'string', 'max:50'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
