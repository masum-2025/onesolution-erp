<?php

namespace Modules\Pharmacy\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Pharmacy\Services\PharmacySaleGuard;
use Modules\Pos\Contracts\SaleGuard;

/**
 * Pharmacy: Medicine shop: medicines by brand and generic, selling by piece, strip or box within MRP, expiry and the controlled-drug register.
 */
class PharmacyServiceProvider extends ServiceProvider
{
    /** Writes a person may send a minute. */
    private const WRITES_PER_MINUTE = 60;

    /** The controlled register and reports a person may read a minute. */
    private const REPORTS_PER_MINUTE = 20;

    public function register(): void
    {
        // POS asks the pharmacy about every sale of a medicine (MRP, expiry, prescriptions).
        $this->app->tag([PharmacySaleGuard::class], SaleGuard::TAG);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'pharmacy');

        RateLimiter::for('pharmacy-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('pharmacy-writes:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('pharmacy-reports', fn (Request $request) => Limit::perMinute(self::REPORTS_PER_MINUTE)
            ->by('pharmacy-reports:'.($request->user()?->getKey() ?? $request->ip())));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
