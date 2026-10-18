<?php

namespace Modules\Zkteco\Protocol;

use Modules\Zkteco\Contracts\Terminal;
use Modules\Zkteco\Models\Device;

/** Makes the terminal for a device (tests swap this for a fake). */
class TerminalFactory
{
    /** Seconds to wait for a terminal's answer. */
    private const TIMEOUT = 5;

    public function for(Device $device): Terminal
    {
        return new ZkTerminal(new ZkClient($device->host, $device->port, $device->protocol, (int) ($device->comm_key ?? 0), self::TIMEOUT));
    }
}
