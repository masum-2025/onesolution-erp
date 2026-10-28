<?php

namespace Modules\Compliance\Console;

use Illuminate\Console\Command;
use Modules\Compliance\Services\Reminders;

/** Daily: tell the people who manage compliance about audits that expire soon or have expired (each stage once per expiry day). */
class SendExpiryReminders extends Command
{
    protected $signature = 'compliance:expiry-reminders';

    protected $description = 'Remind about factory audits that expire soon or have expired';

    public function handle(Reminders $reminders): int
    {
        $count = $reminders->run();
        $this->info("Reported {$count} audit(s).");

        return self::SUCCESS;
    }
}
