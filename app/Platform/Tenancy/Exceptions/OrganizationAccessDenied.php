<?php

namespace App\Platform\Tenancy\Exceptions;

class OrganizationAccessDenied extends TenancyException
{
    public static function notMember(): self
    {
        return new self('not_member', 403);
    }

    public static function organizationInactive(): self
    {
        return new self('organization_inactive', 403);
    }

    public static function partnerInactive(): self
    {
        return new self('partner_inactive', 403);
    }

    public static function noPartnerAccess(): self
    {
        return new self('not_partner_member', 403);
    }

    /**
     * The account exists, but not at this address (another partner's domain).
     */
    public static function wrongAddress(): self
    {
        return new self('wrong_address', 403);
    }

    public static function writeOutsideScope(): self
    {
        return new self('write_forbidden', 403);
    }
}
