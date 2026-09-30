<?php

namespace App\Platform\Tenancy\Databases;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * The client's data is being copied to another database; changes wait until
 * the move is finished (reading still works).
 */
class TenantDataMoving extends TenancyException
{
    public function __construct()
    {
        parent::__construct('data_moving', 503, extra: ['retry_after' => 300]);
    }
}
