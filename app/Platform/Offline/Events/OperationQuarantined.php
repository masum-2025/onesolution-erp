<?php

namespace App\Platform\Offline\Events;

use App\Platform\Offline\Models\QuarantinedOperation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An offline change was held for an admin's decision (Phase 7; alerts in
 * Phase 9 monitoring). After commit.
 */
class OperationQuarantined implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public QuarantinedOperation $operation) {}
}
