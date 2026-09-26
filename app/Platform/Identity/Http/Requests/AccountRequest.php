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
            'marketing' => ['sometimes', 'boolean'],
        ];
    }
}
