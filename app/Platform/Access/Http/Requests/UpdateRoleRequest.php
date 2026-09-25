<?php

namespace App\Platform\Access\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class UpdateRoleRequest extends StrictFormRequest
{
    use RoleAttributeRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->roleRules(creating: false),
            // Optimistic concurrency: the version the editor started from.
            'base_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
