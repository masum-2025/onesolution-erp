<?php

namespace Modules\Patients\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Patients\Contracts\PatientDirectory;
use Modules\Patients\Directory\Patients;

/**
 * Patients: one record per patient for the whole company, its history, the
 * "who looked" log and the patient portal. Other modules use PatientDirectory.
 */
class PatientsServiceProvider extends ServiceProvider
{
    /** Writes a person may send a minute. */
    private const WRITES_PER_MINUTE = 60;

    /** Searches a person may run a minute (reception types fast; a scraper should not). */
    private const SEARCHES_PER_MINUTE = 90;

    public function register(): void
    {
        $this->app->bind(PatientDirectory::class, Patients::class);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'patients');

        RateLimiter::for('patients-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('patients-writes:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('patients-search', fn (Request $request) => Limit::perMinute(self::SEARCHES_PER_MINUTE)
            ->by('patients-search:'.($request->user()?->getKey() ?? $request->ip())));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
