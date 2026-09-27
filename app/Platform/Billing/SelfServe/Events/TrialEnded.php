<?php

namespace App\Platform\Billing\SelfServe\Events;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A free trial ran out without a purchase; the account is back on its free
 * plan with all its data. After commit.
 */
class TrialEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Organization $organization, public string $planKey) {}
}
