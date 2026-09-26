<?php

namespace App\Platform\Billing\Events;

use App\Platform\Billing\Models\Invoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invoice (not a credit note) was issued, to a partner or a client.
 * After commit.
 */
class InvoiceIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Invoice $invoice) {}
}
