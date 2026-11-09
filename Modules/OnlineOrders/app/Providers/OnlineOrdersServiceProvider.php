<?php

namespace Modules\OnlineOrders\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\OnlineOrders\Export\OnlineOrdersExporter;

/**
 * Online orders: orders from every channel (typed in now; Facebook selling
 * and the website shop place them through the public Orders service),
 * stock held and shipped through Inventory's Stock, posted through
 * Accounting's Ledger where the company keeps books, customers through
 * CRM's Customers when it is on.
 */
class OnlineOrdersServiceProvider extends ServiceProvider
{
    /** Changes a person may send a minute (a busy shop moves many orders on). */
    private const WRITES_PER_MINUTE = 60;

    public function register(): void
    {
        // The client's data export includes online orders (ExportsModuleData).
        $this->app->tag([OnlineOrdersExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'online_orders');

        RateLimiter::for('online-orders-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('online-orders-writes:'.($request->user()?->getKey() ?? $request->ip())));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
