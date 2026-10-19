<?php

namespace Modules\Leave\Console;

use Illuminate\Console\Command;
use Modules\Leave\Services\Accruals;

/**
 * Every night: leave coming in (yearly, monthly, earned) and carried into
 * a new leave year, for every company with Leave on. Running twice the same
 * day changes nothing.
 */
class AccrueLeave extends Command
{
    protected $signature = 'leave:accrue';

    protected $description = 'Give leave that has come in (yearly, monthly, earned, carried over).';

    public function handle(Accruals $accruals): int
    {
        $this->info('Wrote '.$accruals->run().' leave ledger line(s).');

        return self::SUCCESS;
    }
}
