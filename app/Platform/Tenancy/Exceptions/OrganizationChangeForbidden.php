<?php

namespace App\Platform\Tenancy\Exceptions;

/**
 * A record's organization, or an organization's place in the tree, can only
 * change through the audited move / transfer actions.
 */
class OrganizationChangeForbidden extends TenancyException
{
    public function __construct()
    {
        parent::__construct('organization_change_forbidden', 422);
    }
}
