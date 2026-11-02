<?php

namespace App\Platform\Addons\Console;

use App\Platform\Addons\Addons;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Daily: end finished trials and cancelled add-ons, bill the next month of the others. */
class RunAddonCycle extends Command
{
    protected $signature = 'addons:cycle';

    protected $description = 'End finished add-on trials and cancellations, renew running add-ons';

    public function handle(Addons $addons): int
    {
        $result = $addons->cycle(CarbonImmutable::now());

        $this->info("{$result['renewed']} add-ons renewed, {$result['ended']} ended.");

        return self::SUCCESS;
    }
}
