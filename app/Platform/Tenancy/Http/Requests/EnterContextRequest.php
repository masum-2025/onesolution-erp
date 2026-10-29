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
            'organization_id' => ['required_without_all:partner_id,support_grant_id,connection_id', 'prohibits:partner_id,support_grant_id,connection_id', 'string', 'ulid'],
            'partner_id' => ['required_without_all:organization_id,support_grant_id,connection_id', 'prohibits:support_grant_id,connection_id', 'string', 'ulid'],
            // Browser sessions only: partner staff entering a client with approved support access.
            'support_grant_id' => ['sometimes', 'prohibits:connection_id', 'string', 'ulid'],
            // Browser sessions only: a connected provider's person in one shared factory of its client (NET-2).
            'connection_id' => ['required_with:unit_id', 'string', 'ulid'],
            'unit_id' => ['required_with:connection_id', 'string', 'ulid'],
        ];
    }
}
