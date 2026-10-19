<?php

namespace Modules\Attendance\Console;

use Illuminate\Console\Command;
use Modules\Attendance\Services\AbsenceStreaks;

/**
 * Every morning after attendance:close-days: people absent for the set
 * number of working days in a row are reported to HR, once per streak.
 */
class AbsenceAlerts extends Command
{
    protected $signature = 'attendance:absence-alerts';

    protected $description = 'Tell HR about people absent many working days in a row.';

    public function handle(AbsenceStreaks $streaks): int
    {
        $this->info('Reported '.$streaks->run().' person(s).');

        return self::SUCCESS;
    }
}
