<?php

namespace App\Platform\Identity\Events;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A workspace owned only by a person who deleted their account was closed
 * (archived, name removed). Modules listen to delete their business data of
 * it; records other organizations own are never touched. After commit.
 */
class WorkspaceErased implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Organization $organization) {}
}
