<?php

namespace App\Platform\PartnerApi\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

class ApiMemberRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            // A unit inside the client (default: its top organization).
            'organization_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'membership_type' => ['required', Rule::in(['owner', 'staff'])],
            'access_scope' => ['sometimes', Rule::in(['own', 'descendants'])],
        ];
    }
}
