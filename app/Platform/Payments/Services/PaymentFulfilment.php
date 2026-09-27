<?php

namespace App\Platform\Payments\Services;

use App\Platform\Billing\SelfServe\Fulfilment;
use App\Platform\Payments\Contracts\PaymentFulfiller;
use App\Platform\Payments\Models\Payment;

/**
 * What a confirmed payment gives, by what it was for: a self-serve plan or
 * invoice (paying us), or a customer's collection (paying a client).
 */
class PaymentFulfilment implements PaymentFulfiller
{
    public function __construct(private Fulfilment $selfServe, private CollectionFulfilment $collections) {}

    public function fulfil(Payment $payment): void
    {
        $payment->purpose === Payment::COLLECTION
            ? $this->collections->fulfil($payment)
            : $this->selfServe->fulfil($payment);
    }
}
