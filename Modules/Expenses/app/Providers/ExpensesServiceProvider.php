<?php

namespace Modules\Expenses\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Expenses\Export\ExpensesExporter;
use Modules\Expenses\Listeners\PayClaimsWithPayroll;
use Modules\Expenses\Payroll\ClaimsWithSalary;
use Modules\Payroll\Contracts\EarningSource;
use Modules\Payroll\Events\PayrollApproved;

/**
 * Expenses: employees' expense claims (HRM's employees, read through its
 * EmployeeDirectory), approved step by step, into the books through
 * Accounting's Ledger when the company keeps them, and paid back in cash,
 * by bank or with a month's salary (Payroll's EarningSource contract and
 * its payroll.run.approved event) when Payroll is on.
 */
class ExpensesServiceProvider extends ServiceProvider
{
    /** Changes a person may send a minute. */
    private const WRITES_PER_MINUTE = 30;

    /** Receipts a person may send a minute (each is read and kept). */
    private const UPLOADS_PER_MINUTE = 10;

    public function register(): void
    {
        // Claims paid with a month's salary go on the slips (Payroll's EarningSource), when Payroll is installed.
        if (interface_exists(EarningSource::class)) {
            $this->app->tag([ClaimsWithSalary::class], 'payroll.earning_sources');
        }
        // The client's data export includes expenses (ExportsModuleData).
        $this->app->tag([ExpensesExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'expenses');

        RateLimiter::for('expenses-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('expenses-writes:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('expenses-uploads', fn (Request $request) => Limit::perMinute(self::UPLOADS_PER_MINUTE)
            ->by('expenses-uploads:'.($request->user()?->getKey() ?? $request->ip())));

        // A month's payroll approved: the claims paid with that salary are paid.
        if (class_exists(PayrollApproved::class)) {
            Event::listen(PayrollApproved::class, PayClaimsWithPayroll::class);
        }

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
            Route::middleware('web')->group($root.'/routes/web.php');
        }
    }
}
