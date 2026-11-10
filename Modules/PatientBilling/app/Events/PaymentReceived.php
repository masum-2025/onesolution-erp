<?php

namespace Modules\PatientBilling\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money came in (a payment on a bill or an advance) (manifest event "patient_billing.payment_received"). Ids and amounts only.
 */
class PaymentReceived
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $paymentId, public ?string $billId, public string $kind, public string $method, public int $amountMinor, public string $currency,
    ) {}

    public function name(): string
    {
        return 'patient_billing.payment_received';
    }
}
