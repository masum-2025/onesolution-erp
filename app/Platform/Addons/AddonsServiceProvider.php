<?php

namespace App\Platform\Addons;

use App\Platform\Addons\Console\RunAddonCycle;
use App\Platform\Addons\Console\SetAddonPrice;
use App\Platform\Addons\Console\SyncAddonPrices;
use Illuminate\Support\ServiceProvider;

/**
 * The add-on store: modules an account buys beyond its plan. Clients never
 * upload code; the platform deploys every module and the store only grants
 * and bills the right to one. Platform prices are set with artisan commands.
 */
class AddonsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncAddonPrices::class, SetAddonPrice::class, RunAddonCycle::class]);
        }
    }
}
