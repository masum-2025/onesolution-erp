<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class ContactRequest extends StrictFormRequest
{
    use IdentityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->addressRules(),
            'current_password' => ['required', 'string', 'max:255'],
        ];
    }
}
