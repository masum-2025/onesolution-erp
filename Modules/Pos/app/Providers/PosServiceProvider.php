<?php

namespace Modules\Pos\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Pos\Export\PosExporter;

/**
 * Point of sale: counters, shifts, sales and returns, offline sales. Stock
 * moves through Inventory's public Stock service; sales are posted through
 * Accounting's Ledger where the company keeps books.
 */
class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PosExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'pos');

        // A busy counter makes a sale every few seconds; still bounded per person.
        RateLimiter::for('pos-reports', fn (Request $request) => Limit::perMinute(30)->by('pos-reports:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('pos-sales', fn (Request $request) => Limit::perMinute(60)->by('pos-sales:'.($request->user()?->getKey() ?? $request->ip())));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
