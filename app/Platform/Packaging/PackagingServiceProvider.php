<?php

namespace App\Platform\Packaging;

use App\Platform\Packaging\Console\SyncPackaging;
use Illuminate\Support\ServiceProvider;

class PackagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Read from the data files, so the module registry can use plans at boot.
        $this->app->singleton(PlanCatalog::class, fn () => PlanCatalog::fromDataFile());
        $this->app->singleton(SectorCatalog::class, fn () => SectorCatalog::fromDataFile());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncPackaging::class]);
        }
    }
}
