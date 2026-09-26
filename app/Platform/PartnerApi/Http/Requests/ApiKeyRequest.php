<?php

namespace App\Platform\PartnerApi\Http\Requests;

use App\Platform\PartnerApi\Models\PartnerApiKey;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

class ApiKeyRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // What the key is for, e.g. "Website sign-up".
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', Rule::in(PartnerApiKey::SCOPES)],
        ];
    }
}
