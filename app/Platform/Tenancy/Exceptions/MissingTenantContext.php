<?php

namespace App\Platform\Tenancy\Exceptions;

/**
 * Thrown when tenant-scoped data is touched without an active organization.
 * Fails closed: better an error than a query across every tenant.
 */
class MissingTenantContext extends TenancyException
{
    public function __construct()
    {
        parent::__construct('no_context', 403);
    }
}
