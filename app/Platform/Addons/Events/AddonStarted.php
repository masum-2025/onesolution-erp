<?php

namespace App\Platform\Addons\Events;

use App\Models\User;
use App\Platform\Addons\Models\OrganizationAddon;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An account started an add-on: on trial or bought. Dispatched after the change commits. */
class AddonStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public OrganizationAddon $addon, public ?User $actor) {}
}
