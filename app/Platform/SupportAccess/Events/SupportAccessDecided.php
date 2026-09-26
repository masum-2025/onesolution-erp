<?php

namespace App\Platform\SupportAccess\Events;

use App\Platform\SupportAccess\Models\SupportGrant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client approved or rejected a support request. After commit.
 */
class SupportAccessDecided implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SupportGrant $grant) {}
}
