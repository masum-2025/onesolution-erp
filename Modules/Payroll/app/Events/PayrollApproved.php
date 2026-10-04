<?php

namespace Modules\Payroll\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A month's payroll was approved (manifest event payroll.run.approved).
 * Ids only: listeners read what they need through Payroll's own services.
 */
final class PayrollApproved
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly string $companyId,
        public readonly string $period,
    ) {}
}
