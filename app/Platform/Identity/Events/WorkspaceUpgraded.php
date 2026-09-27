<?php

namespace App\Platform\Identity\Events;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A personal workspace became a company (same organization, all data kept).
 * After commit.
 */
class WorkspaceUpgraded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Organization $organization, public User $user) {}
}
