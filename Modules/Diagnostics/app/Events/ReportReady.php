<?php

namespace Modules\Diagnostics\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every test of an order is verified: the report can be given (manifest event "diagnostics.report_ready"). Ids only.
 */
class ReportReady
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $orderId, public string $patientId,
    ) {}

    public function name(): string
    {
        return 'diagnostics.report_ready';
    }
}
