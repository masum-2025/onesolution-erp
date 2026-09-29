<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * The browser's passkey answer (navigator.credentials.create/get, as JSON).
 * The answer itself is checked by PasskeyService, not here.
 */
class PasskeyRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string', 'max:1400'],
            'credential.rawId' => ['required', 'string', 'max:1400'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ];
    }
}
