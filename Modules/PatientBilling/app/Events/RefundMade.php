<?php

namespace Modules\PatientBilling\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money went back to a patient (manifest event "patient_billing.refund_made"). Ids and amounts only.
 */
class RefundMade
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $refundId, public ?string $billId, public string $method, public int $amountMinor, public string $currency,
    ) {}

    public function name(): string
    {
        return 'patient_billing.refund_made';
    }
}
