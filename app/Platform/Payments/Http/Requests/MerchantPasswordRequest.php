<?php

namespace App\Platform\Payments\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Approving a change or turning an account back on: the version seen on
 * screen and the person's password.
 */
class MerchantPasswordRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'current_password' => ['required', 'string', 'max:255'],
        ];
    }
}
