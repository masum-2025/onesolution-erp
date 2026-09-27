<?php

namespace App\Platform\Billing\SelfServe\Events;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An overdue self-serve account became read-only (no data is touched). After commit.
 */
class WorkspaceRestricted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Organization $organization) {}
}
