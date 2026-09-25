<?php

namespace App\Platform\Tenancy\Exceptions;

class MembershipConflict extends TenancyException
{
    public static function alreadyMember(): self
    {
        return new self('already_member', 422);
    }

    public static function ownMembership(): self
    {
        return new self('own_membership', 422);
    }

    public static function currentContext(): self
    {
        return new self('own_context_status', 422);
    }
}
