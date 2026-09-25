<?php

namespace App\Platform\Tenancy\Exceptions;

/**
 * Same response whether the organization does not exist or belongs to
 * someone else, so ids cannot be probed.
 */
class OrganizationNotFound extends TenancyException
{
    public function __construct()
    {
        parent::__construct('organization_not_found', 404);
    }
}
