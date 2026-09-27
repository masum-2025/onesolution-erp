<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class AccountRequest extends StrictFormRequest
{
    use IdentityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'locale' => ['sometimes', ...array_slice($this->localeRule(true), 1)],
            // The person's own timezone for times on screen (Phase 6); null = the organization's.
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'marketing' => ['sometimes', 'boolean'],
        ];
    }
}
