<?php

namespace Modules\PatientBilling\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A cashier's day was closed (within the limit, or after review) (manifest event "patient_billing.day_closed"). Ids and amounts only.
 */
class CashDayClosed
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $cashDayId, public int $varianceMinor, public string $currency,
    ) {}

    public function name(): string
    {
        return 'patient_billing.day_closed';
    }
}
