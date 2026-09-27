<?php

namespace App\Platform\Billing\SelfServe\Events;

use App\Platform\Billing\Models\Invoice;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reminder: a self-serve invoice is past its due date, and the account
 * becomes read-only on $restrictsOn unless it is paid. After commit.
 */
class PaymentOverdue implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Organization $organization,
        public Invoice $invoice,
        public CarbonImmutable $restrictsOn,
    ) {}
}
