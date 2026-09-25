<?php

namespace App\Platform\Tenancy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'membership_type' => ['required', Rule::enum(MembershipType::class)],
            'access_scope' => ['sometimes', Rule::enum(AccessScope::class)],
        ];
    }
}
