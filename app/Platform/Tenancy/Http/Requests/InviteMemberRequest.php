<?php

namespace App\Platform\Tenancy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use Illuminate\Validation\Rule;

/**
 * Inviting someone by email: a new account gets a link to set a password
 * (no sign-up), an existing one is told it was given access. Roles may be
 * given at once (checked as when changed later).
 */
class InviteMemberRequest extends StrictFormRequest
{
    /** Roles one invitation may give. */
    private const MAX_ROLES = 20;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'membership_type' => ['required', Rule::in([MembershipType::Staff->value, MembershipType::Owner->value])],
            'access_scope' => ['sometimes', Rule::enum(AccessScope::class)],
            'role_ids' => ['sometimes', 'array', 'max:'.self::MAX_ROLES],
            'role_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }
}
