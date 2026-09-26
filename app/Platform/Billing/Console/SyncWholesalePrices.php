<?php

namespace App\Platform\Billing\Console;

use App\Platform\Billing\Services\WholesalePriceBook;
use Illuminate\Console\Command;

/**
 * Records database/seeders/data/wholesale-prices.php as the default
 * wholesale prices. Safe to run again (on every deploy).
 */
class SyncWholesalePrices extends Command
{
    protected $signature = 'billing:sync-prices';

    protected $description = 'Record the default wholesale prices from the data file';

    public function handle(WholesalePriceBook $book): int
    {
        $added = $book->syncDefaults(require database_path('seeders/data/wholesale-prices.php'));

        $this->info("{$added} wholesale prices recorded.");

        return self::SUCCESS;
    }
}
