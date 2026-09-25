<?php

namespace App\Platform\Modules\Contracts;

use App\Platform\Tenancy\Models\Organization;

/**
 * Implemented by a module that owns data, and tagged in its service provider:
 *
 *     $this->app->tag([PurgePayrollData::class], 'module.purgers.payroll');
 *
 * Called only by the delayed, confirmed purge flow — never on disable.
 */
interface PurgesModuleData
{
    /**
     * Delete this module's data for the organization and its descendants.
     *
     * @return int Number of records deleted (for the audit trail).
     */
    public function purge(Organization $organization): int;
}
