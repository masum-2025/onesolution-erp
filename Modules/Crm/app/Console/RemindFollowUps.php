<?php

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Crm\Services\Activities;

/** Every few minutes: remind people of their follow-ups due soon (each once). */
class RemindFollowUps extends Command
{
    protected $signature = 'crm:remind';

    protected $description = 'Remind people of their CRM follow-ups that are due soon';

    public function handle(Activities $activities): int
    {
        $count = $activities->remind();
        $this->info("Reminded about {$count} follow-up(s).");

        return self::SUCCESS;
    }
}
