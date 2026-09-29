<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Confirming it is you before a sensitive action: one of an app code, a
 * recovery code or a passkey answer.
 */
class StepUpRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'required_without_all:recovery_code,credential', 'string', 'max:12'],
            'recovery_code' => ['nullable', 'required_without_all:code,credential', 'string', 'max:20'],
            'credential' => ['nullable', 'required_without_all:code,recovery_code', 'array'],
            'credential.id' => ['required_with:credential', 'string', 'max:1400'],
            'credential.rawId' => ['required_with:credential', 'string', 'max:1400'],
            'credential.type' => ['required_with:credential', 'string', 'in:public-key'],
            'credential.response' => ['required_with:credential', 'array'],
        ];
    }
}
