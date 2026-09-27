<?php

namespace App\Platform\Payments\Events;

use App\Platform\Payments\Models\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The gateway reported a payment as failed or cancelled. After commit.
 */
class PaymentFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Payment $payment) {}
}
