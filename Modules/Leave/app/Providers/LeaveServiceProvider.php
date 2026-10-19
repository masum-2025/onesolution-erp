<?php

namespace Modules\Leave\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Leave\Attendance\LeaveDays;
use Modules\Leave\Console\AccrueLeave;
use Modules\Leave\Export\LeaveExporter;
use Modules\Leave\Payroll\EncashedDays;
use Modules\Payroll\Contracts\DaysToPay;

/**
 * Leave: each company's kinds of leave, the balance ledger, requests and
 * their approval steps, for HRM's employees (read through HRM's
 * EmployeeDirectory). Approved leave reaches Attendance through its
 * LeaveSource contract, and leave without pay Payroll through Attendance.
 */
class LeaveServiceProvider extends ServiceProvider
{
    /** Changes a person may send a minute (asking, deciding, types, balances). */
    private const WRITES_PER_MINUTE = 30;

    public function register(): void
    {
        // Attendance asks every tagged source whether someone is on leave that day.
        $this->app->tag([LeaveDays::class], 'attendance.leave_sources');
        // Payroll pays approved leave pay-outs (its DaysToPay contract), when Payroll is installed.
        if (interface_exists(DaysToPay::class)) {
            $this->app->tag([EncashedDays::class], 'payroll.days_to_pay');
        }
        // The client's data export includes leave (ExportsModuleData).
        $this->app->tag([LeaveExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'leave');

        RateLimiter::for('leave-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('leave-writes:'.($request->user()?->getKey() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([AccrueLeave::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Before attendance:close-days (01:40), so a new year's leave is there first; running twice changes nothing.
            $schedule->command('leave:accrue')->dailyAt('01:20')->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
