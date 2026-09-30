<?php

namespace App\Platform\Audit\Shipping;

use App\Platform\Audit\Shipping\Contracts\AuditShipper;
use Illuminate\Support\Facades\Log;

/**
 * Writes each entry as one JSON line on the "audit" log channel, for the log
 * collector to carry to write-once storage.
 */
class LogShipper implements AuditShipper
{
    public function name(): string
    {
        return 'log';
    }

    public function ship(array $entries): void
    {
        $channel = Log::channel('audit');

        foreach ($entries as $entry) {
            $channel->info('audit.entry', $entry);
        }
    }
}
