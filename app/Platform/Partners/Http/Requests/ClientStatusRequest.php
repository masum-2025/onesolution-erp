<?php

namespace App\Platform\Partners\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class ClientStatusRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:active,suspended'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
