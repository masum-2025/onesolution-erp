<?php

namespace App\Platform\Identity\Events;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A self-serve person got their own workspace (e.g. billing starts a trial,
 * Phase 5C-2). After commit.
 */
class PersonalWorkspaceCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Organization $workspace, public User $owner) {}
}
