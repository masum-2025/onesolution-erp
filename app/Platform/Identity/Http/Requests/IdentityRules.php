<?php

namespace App\Platform\Identity\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Field rules shared by the sign-up, recovery and account forms.
 */
trait IdentityRules
{
    /**
     * @return array<string, mixed>
     */
    protected function newPasswordRules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(10)->letters()->numbers()],
            'password_confirmation' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * An email or a phone (with its country), by channel.
     *
     * @return array<string, mixed>
     */
    protected function addressRules(): array
    {
        return [
            'channel' => ['required', Rule::in(['mail', 'sms'])],
            'email' => ['required_if:channel,mail', 'nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['required_if:channel,sms', 'nullable', 'string', 'max:30', 'regex:/^[\d\s()+\-]+$/'],
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function codeRules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'ulid'],
            'code' => ['required', 'string', 'digits:'.(int) config('identity.otp.length')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function localeRule(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', Rule::in((array) config('tenancy.supported_locales'))];
    }
}
