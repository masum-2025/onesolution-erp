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
            'organization_id' => ['required_without:partner_id', 'prohibits:partner_id', 'string', 'ulid'],
            'partner_id' => ['required_without:organization_id', 'string', 'ulid'],
        ];
    }
}
