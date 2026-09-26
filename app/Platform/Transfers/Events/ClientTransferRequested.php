<?php

namespace App\Platform\Transfers\Events;

use App\Platform\Transfers\Models\ClientTransfer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client asked to move to a partner, which now has to accept. After commit.
 */
class ClientTransferRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public ClientTransfer $transfer) {}
}
