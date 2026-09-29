<?php

namespace App\Platform\Offline\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Setting up this browser or app for offline work: a name the person
 * recognises ("Shop counter tablet") and, optionally, its platform.
 */
class RegisterDeviceRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'platform' => ['nullable', 'string', 'max:60'],
        ];
    }
}
