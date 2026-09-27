<?php

namespace App\Platform\Payments\Services;

use App\Platform\Payments\Contracts\PaymentFulfiller;
use App\Platform\Payments\Events\PaymentCollected;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\PaymentCollectables;

/**
 * A customer's confirmed payment to a client: the owning module marks its
 * record paid (inside the confirmation's transaction), then
 * "payments.collected" goes out after commit. Runs even while the module is
 * switched off: the money was taken, so the record must say so.
 */
class CollectionFulfilment implements PaymentFulfiller
{
    public function __construct(private PaymentCollectables $collectables) {}

    public function fulfil(Payment $payment): void
    {
        $this->collectables->provider((string) $payment->subject_type)->paid($payment);

        PaymentCollected::dispatch($payment);
    }
}
