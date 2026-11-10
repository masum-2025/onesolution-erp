<?php

namespace Modules\PatientBilling\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bill was finalized (manifest event "patient_billing.bill_finalized"). Ids and amounts only.
 */
class BillFinalized
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $billId, public string $patientId, public int $totalMinor, public string $currency,
    ) {}

    public function name(): string
    {
        return 'patient_billing.bill_finalized';
    }
}
