<?php

namespace Modules\Fleet\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Fleet: The company's vehicles and their trips: who drove, how far, and what the fuel cost.
 */
class FleetServiceProvider extends ServiceProvider
{
    /** Writes a person may send a minute. */
    private const WRITES_PER_MINUTE = 60;

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'fleet');

        RateLimiter::for('fleet-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('fleet-writes:'.($request->user()?->getKey() ?? $request->ip())));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
