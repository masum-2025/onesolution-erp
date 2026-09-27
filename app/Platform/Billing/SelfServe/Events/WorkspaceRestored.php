<?php

namespace App\Platform\Billing\SelfServe\Events;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A read-only self-serve account works normally again (paid, or moved to a free plan). After commit.
 */
class WorkspaceRestored implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Organization $organization) {}
}
