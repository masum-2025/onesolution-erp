<?php

namespace App\Platform\Payments\Events;

use App\Platform\Payments\Models\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "payments.collected": a customer paid a client into its own merchant
 * account, and the owning module marked its record paid. Modules listen for
 * their own receipts and messages. After commit.
 */
class PaymentCollected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Payment $payment) {}
}
