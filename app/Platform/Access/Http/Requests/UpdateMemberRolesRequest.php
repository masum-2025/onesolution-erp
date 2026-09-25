<?php

namespace App\Platform\Access\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class UpdateMemberRolesRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The full list after the change; [] removes every role.
            'role_ids' => ['present', 'array', 'max:50'],
            'role_ids.*' => ['string', 'distinct', 'size:26'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
