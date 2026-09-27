<?php

namespace App\Platform\Billing\SelfServe\Events;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A free trial of a paid personal plan started. After commit.
 */
class TrialStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Organization $organization) {}
}
