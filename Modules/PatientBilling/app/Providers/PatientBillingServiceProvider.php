<?php

namespace Modules\PatientBilling\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\PatientBilling\Contracts\PatientCharges;
use Modules\PatientBilling\Services\Charges;

/**
 * Patient billing: bills, payments and advances, discounts with approval,
 * refunds, panels and the cash day. Other departments use PatientCharges.
 */
class PatientBillingServiceProvider extends ServiceProvider
{
    /** Writes a person may send a minute. */
    private const WRITES_PER_MINUTE = 60;

    /** Payments and refunds a person may send a minute. */
    private const MONEY_PER_MINUTE = 40;

    /** Patient searches a person may run a minute. */
    private const SEARCHES_PER_MINUTE = 90;

    public function register(): void
    {
        $this->app->bind(PatientCharges::class, Charges::class);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'patient_billing');

        $by = fn (string $name, int $perMinute) => RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)
            ->by($name.':'.($request->user()?->getKey() ?? $request->ip())));
        $by('patient-billing-writes', self::WRITES_PER_MINUTE);
        $by('patient-billing-money', self::MONEY_PER_MINUTE);
        $by('patient-billing-search', self::SEARCHES_PER_MINUTE);

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
