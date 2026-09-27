<?php

namespace App\Platform\Countries;

use App\Platform\Countries\Console\SyncCountries;
use Illuminate\Support\ServiceProvider;

/**
 * Countries (Phase 6): facts per country as data files, feeding organization
 * defaults (language, timezone, currency, region), phone formats and rule
 * defaults. See config/countries.php.
 */
class CountriesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CountryCatalog::class, fn () => CountryCatalog::fromDataFiles());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncCountries::class]);
        }
    }
}
