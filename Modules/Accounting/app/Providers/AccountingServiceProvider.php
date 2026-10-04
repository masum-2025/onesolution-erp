<?php

namespace Modules\Accounting\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Accounting\Charts\ChartTemplates;
use Modules\Accounting\Console\MapPostingAccounts;
use Modules\Accounting\Console\SeedTaxCodes;
use Modules\Accounting\Export\AccountingExporter;

/**
 * Accounting: a company's chart of accounts, fiscal years, journals and
 * reports. Business data lives in the client's database (tenant tables);
 * other modules post through Modules\Accounting\Services\Ledger only.
 */
class AccountingServiceProvider extends ServiceProvider
{
    /** Reports read many rows: at most this many per person per minute. */
    private const REPORTS_PER_MINUTE = 60;

    public function register(): void
    {
        $this->app->singleton(ChartTemplates::class);
        // The client's data export includes the books (ExportsModuleData).
        $this->app->tag([AccountingExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'accounting');

        RateLimiter::for('accounting-reports', fn (Request $request) => Limit::perMinute(self::REPORTS_PER_MINUTE)
            ->by('accounting-reports:'.($request->user()?->getKey() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([MapPostingAccounts::class, SeedTaxCodes::class]);
        }

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
