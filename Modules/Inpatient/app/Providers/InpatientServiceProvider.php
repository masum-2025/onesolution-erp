<?php

namespace Modules\Inpatient\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Inpatient\Console\ChargeBedDays;

/**
 * Inpatient: Admitted patients: wards and beds, admission with an advance, the bed board, bed moves, daily bed charges on the bill, rounds, nursing notes and discharge.
 */
class InpatientServiceProvider extends ServiceProvider
{
    /** Writes a person may send a minute. */
    private const WRITES_PER_MINUTE = 60;

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'inpatient');

        RateLimiter::for('inpatient-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('inpatient-writes:'.($request->user()?->getKey() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([ChargeBedDays::class]);
        }
        // Bed days begin at midnight or every 24 hours from admission (rule), so hourly; each day is charged once.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('inpatient:bed-charges')->hourlyAt(5)->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
