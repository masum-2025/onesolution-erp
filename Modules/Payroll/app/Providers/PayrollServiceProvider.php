<?php

namespace Modules\Payroll\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Payroll\Export\PayrollExporter;

/**
 * Payroll: pay make-up, employees' salaries, monthly runs with approval,
 * payslips. Employees come from HRM (EmployeeDirectory), days from
 * Attendance (AttendanceSummary); approved runs are posted through
 * Accounting's Ledger where the company keeps books.
 */
class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The client's data export includes payroll (ExportsModuleData).
        $this->app->tag([PayrollExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'payroll');

        // Bank files carry full account numbers: a few a minute per person.
        RateLimiter::for('payroll-bank-file', fn (Request $request) => Limit::perMinute(5)->by('payroll-bank-file:'.($request->user()?->getKey() ?? $request->ip())));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
