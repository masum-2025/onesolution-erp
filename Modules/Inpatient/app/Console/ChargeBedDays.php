<?php

namespace Modules\Inpatient\Console;

use Illuminate\Console\Command;
use Modules\Inpatient\Services\BedCharges;

/** Hourly: put each bed day that has begun on the patient's bill, once (companies with Inpatient on only). */
class ChargeBedDays extends Command
{
    protected $signature = 'inpatient:bed-charges';

    protected $description = 'Charge the bed days of admitted patients that have begun';

    public function handle(BedCharges $charges): int
    {
        $count = $charges->run();
        $this->info("Charged {$count} bed day(s).");

        return self::SUCCESS;
    }
}
