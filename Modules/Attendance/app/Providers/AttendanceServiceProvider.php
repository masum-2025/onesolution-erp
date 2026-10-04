<?php

namespace Modules\Attendance\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Attendance\Console\CloseDays;
use Modules\Attendance\Export\AttendanceExporter;

/**
 * Attendance: shifts, holidays, rosters, punches, days and corrections of a
 * company's employees (HRM's, read through HRM's EmployeeDirectory).
 * Payroll reads Modules\Attendance\Services\AttendanceSummary.
 */
class AttendanceServiceProvider extends ServiceProvider
{
    /** Punches one person may send a minute (a tap or two, retries). */
    private const PUNCHES_PER_MINUTE = 6;

    public function register(): void
    {
        // The client's data export includes attendance (ExportsModuleData).
        $this->app->tag([AttendanceExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'attendance');

        RateLimiter::for('attendance-punch', fn (Request $request) => Limit::perMinute(self::PUNCHES_PER_MINUTE)
            ->by('attendance-punch:'.($request->user()?->getKey() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([CloseDays::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Each company's "yesterday" (its own timezone) is over by then; working a day out twice changes nothing.
            $schedule->command('attendance:close-days')->dailyAt('01:40')->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
