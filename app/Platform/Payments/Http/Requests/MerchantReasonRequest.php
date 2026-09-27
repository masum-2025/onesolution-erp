<?php

namespace App\Platform\Payments\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Rejecting a change or turning an account off: the version seen on screen
 * and an optional reason for the audit log.
 */
class MerchantReasonRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
