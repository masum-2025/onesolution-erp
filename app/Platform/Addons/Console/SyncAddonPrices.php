<?php

namespace App\Platform\Addons\Console;

use App\Platform\Addons\AddonPriceBook;
use Illuminate\Console\Command;

/**
 * Records database/seeders/data/addon-prices.php as the platform's add-on
 * prices (list and wholesale). Safe to run again (on every deploy).
 */
class SyncAddonPrices extends Command
{
    protected $signature = 'addons:sync-prices';

    protected $description = 'Record the platform add-on prices from the data file';

    public function handle(AddonPriceBook $book): int
    {
        $added = $book->syncDefaults(require database_path('seeders/data/addon-prices.php'));

        $this->info("{$added} add-on prices recorded.");

        return self::SUCCESS;
    }
}
