<?php

namespace App\Platform\Tenancy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Choosing a context is the one place an id comes from the client: it is
 * verified against the user's memberships and then stored on a new token.
 */
class EnterContextRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['required_without_all:partner_id,support_grant_id', 'prohibits:partner_id,support_grant_id', 'string', 'ulid'],
            'partner_id' => ['required_without_all:organization_id,support_grant_id', 'prohibits:support_grant_id', 'string', 'ulid'],
            // Browser sessions only: partner staff entering a client with approved support access.
            'support_grant_id' => ['sometimes', 'string', 'ulid'],
        ];
    }
}
