<?php

namespace Modules\FactoryErp\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Factory ERP for garments: buyers (CRM), styles and their bill of
 * materials, buyers' orders by size and colour, and what an order needs
 * from the store (Inventory). Reads other modules only through their
 * public services.
 */
class FactoryErpServiceProvider extends ServiceProvider
{
    /** Writes a person may send a minute (order sheets are saved often while typed). */
    private const WRITES_PER_MINUTE = 120;

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'factory_erp');

        RateLimiter::for('factory-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('factory-writes:'.($request->user()?->getKey() ?? $request->ip())));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
