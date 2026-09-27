<?php

namespace App\Platform\Portal\Http\Requests;

use App\Platform\Identity\Http\Requests\IdentityRules;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * A new account to join a portal: the address comes from the invitation.
 */
class PortalSignupRequest extends StrictFormRequest
{
    use IdentityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\s-]+$/'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            ...$this->newPasswordRules(),
        ];
    }
}
