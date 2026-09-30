<?php

namespace App\Platform\Monitoring\Console;

use App\Platform\Monitoring\HealthReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class Heartbeat extends Command
{
    protected $signature = 'monitor:heartbeat';

    protected $description = 'Record that the scheduler is running (read by health:report)';

    public function handle(): int
    {
        Cache::forever(HealthReport::HEARTBEAT, now()->getTimestamp());

        return self::SUCCESS;
    }
}
