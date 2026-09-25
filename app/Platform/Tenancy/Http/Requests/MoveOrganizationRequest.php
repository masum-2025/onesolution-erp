<?php

namespace App\Platform\Tenancy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class MoveOrganizationRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'new_parent_id' => ['required', 'string', 'ulid'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
