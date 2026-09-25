<?php

namespace App\Platform\Access\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class DeleteRoleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
