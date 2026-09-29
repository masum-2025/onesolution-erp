<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * API clients: the second step of a sign-in, with the token the login gave.
 */
class TwoFactorTokenRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'max:12'],
            'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:20'],
        ];
    }
}
