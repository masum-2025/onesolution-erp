<?php

namespace App\Platform\SupportAccess\Events;

use App\Platform\SupportAccess\Models\SupportGrant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A partner asked a client for support access and the client has to decide
 * (auto-approved requests do not wait for anyone). After commit.
 */
class SupportAccessRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SupportGrant $grant) {}
}
