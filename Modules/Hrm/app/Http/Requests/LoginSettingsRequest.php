<?php

namespace Modules\Hrm\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * The company's first password for employees' logins (left out = unchanged)
 * and the roles new logins get. The password is as strong as one a person
 * chooses for themself.
 */
class LoginSettingsRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:0'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255', 'confirmed', Password::min(10)->letters()->numbers()],
            'password_confirmation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role_ids' => ['present', 'array', 'max:20'],
            'role_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }
}
