<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class SignupRequest extends StrictFormRequest
{
    use IdentityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->addressRules(),
            'name' => ['required', 'string', 'min:2', 'max:120'],
            ...$this->newPasswordRules(),
            'locale' => $this->localeRule(),
            'marketing' => ['sometimes', 'boolean'],
            'accept_terms' => ['accepted'],
            // The version of the terms shown on the form (none while no terms are published).
            'terms_version' => ['nullable', 'integer', 'min:1'],
            'bot_token' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['accept_terms.accepted' => __('identity.validation.accept_terms')];
    }
}
