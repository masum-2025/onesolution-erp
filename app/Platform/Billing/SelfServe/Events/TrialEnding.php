<?php

namespace App\Platform\Billing\SelfServe\Events;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A free trial ends soon (rule b2c.trial_reminder_days). After commit.
 */
class TrialEnding implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Organization $organization, public string $planKey, public CarbonImmutable $endsAt) {}
}
