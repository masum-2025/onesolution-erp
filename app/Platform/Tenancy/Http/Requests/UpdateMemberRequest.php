<?php

namespace App\Platform\Tenancy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required_without_all:membership_type,access_scope', Rule::enum(MembershipStatus::class)->except([MembershipStatus::Invited])],
            'membership_type' => ['sometimes', Rule::enum(MembershipType::class)],
            'access_scope' => ['sometimes', Rule::enum(AccessScope::class)],
        ];
    }
}
