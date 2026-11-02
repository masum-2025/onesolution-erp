<?php

namespace App\Platform\Addons\Events;

use App\Platform\Addons\Models\OrganizationAddon;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An add-on ended (trial over, cancelled, or no longer offered); the module is off, its data kept. */
class AddonEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public OrganizationAddon $addon, public string $why) {}
}
