<?php

namespace App\Platform\Countries\Console;

use App\Platform\Countries\CountrySync;
use Illuminate\Console\Command;

/**
 * Mirrors database/data/countries into the database and writes each
 * country's rule defaults. Safe to run again.
 *
 *   php artisan countries:sync
 */
class SyncCountries extends Command
{
    protected $signature = 'countries:sync';

    protected $description = 'Sync countries from their data files and write their rule defaults';

    public function handle(CountrySync $sync): int
    {
        // Country values are written against the rule definitions of this build.
        $this->call('rules:sync');

        $counts = $sync->run();

        $this->info(sprintf('%d countries synced, %d rule values written, %d marked inactive.', $counts['countries'], $counts['rules_written'], $counts['inactive']));

        return self::SUCCESS;
    }
}
