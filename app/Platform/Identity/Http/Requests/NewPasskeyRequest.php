<?php

namespace App\Platform\Identity\Http\Requests;

/**
 * Adding a passkey: the browser's answer and a name the person recognises.
 */
class NewPasskeyRequest extends PasskeyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'min:1', 'max:80'],
        ];
    }
}
