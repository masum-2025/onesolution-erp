<?php

namespace App\Platform\Offline\Events;

use App\Platform\Offline\Models\Device;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A device was removed from offline work; it wipes itself on its next
 * contact. After commit.
 */
class DeviceRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Device $device) {}
}
