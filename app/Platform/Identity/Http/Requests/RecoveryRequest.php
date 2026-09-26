<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class RecoveryRequest extends StrictFormRequest
{
    use IdentityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->addressRules(),
            'locale' => $this->localeRule(),
            'bot_token' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
