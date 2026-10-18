<?php

namespace Modules\Zkteco\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Zkteco\Console\PullDevices;

/**
 * ZKTeco terminals on the office network: their punches are pulled into
 * staff attendance (Attendance's DeviceAttendance contract), several
 * terminals per company, matched to HRM employees by code or by hand.
 */
class ZktecoServiceProvider extends ServiceProvider
{
    /** Changes to terminals a person may send a minute. */
    private const WRITES_PER_MINUTE = 60;

    /** Tries and pulls a person may ask for a minute (each one talks to a terminal). */
    private const ACTIONS_PER_MINUTE = 6;

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'zkteco');

        RateLimiter::for('zkteco-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('zkteco-writes:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('zkteco-actions', fn (Request $request) => Limit::perMinute(self::ACTIONS_PER_MINUTE)
            ->by('zkteco-actions:'.($request->user()?->getKey() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([PullDevices::class]);
            // Every minute; each terminal is read when its rule's minutes have passed.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('zkteco:pull')->everyMinute()->withoutOverlapping()->onOneServer();
            });
        }

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
