<?php

namespace App\Platform\Payments\Contracts;

use App\Platform\Payments\Models\Payment;

/**
 * Gives the payer what a confirmed payment bought (a plan period, a paid
 * invoice). Runs inside the confirmation's transaction: if it fails, the
 * payment stays unconfirmed and the gateway's next message tries again.
 */
interface PaymentFulfiller
{
    public function fulfil(Payment $payment): void;
}
