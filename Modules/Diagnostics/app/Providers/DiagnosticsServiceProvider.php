<?php

namespace Modules\Diagnostics\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Diagnostics\Contracts\TestOrders;
use Modules\Diagnostics\Services\Orders;

/**
 * Diagnostics: tests with normal ranges, orders billed to the patient,
 * barcoded samples, results with flags, verification and reports. Other
 * modules send tests through TestOrders.
 */
class DiagnosticsServiceProvider extends ServiceProvider
{
    /** Writes a person may send a minute (a busy bench enters many results). */
    private const WRITES_PER_MINUTE = 120;

    public function register(): void
    {
        $this->app->bind(TestOrders::class, Orders::class);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'diagnostics');

        RateLimiter::for('diagnostics-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('diagnostics-writes:'.($request->user()?->getKey() ?? $request->ip())));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
