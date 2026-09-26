<?php

namespace App\Platform\Transfers\Events;

use App\Platform\Transfers\Models\ClientTransfer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client now belongs to another partner. After commit.
 */
class ClientTransferred implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public ClientTransfer $transfer) {}
}
