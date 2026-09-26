<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class CurrentPasswordRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:255']];
    }
}
