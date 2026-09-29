<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A code from the authenticator app, or a recovery code (Phase 8-1).
 */
class TwoFactorCodeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'max:12'],
            'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:20'],
        ];
    }
}
