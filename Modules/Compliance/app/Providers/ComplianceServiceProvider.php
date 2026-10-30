<?php

namespace Modules\Compliance\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Compliance\Console\SendExpiryReminders;
use Modules\Compliance\Export\ComplianceExporter;

/**
 * Compliance: the client's audit register (its factories are its companies
 * and branches), with days left worked out from the official expiry days
 * and reminders before they come.
 */
class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The client's data export includes the register (ExportsModuleData).
        $this->app->tag([ComplianceExporter::class], 'module.exporters');
        $this->commands([SendExpiryReminders::class]);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'compliance');

        RateLimiter::for('compliance-writes', fn (Request $request) => Limit::perMinute(60)->by('compliance-writes:'.($request->user()?->getKey() ?? $request->ip())));
        // A file is read whole each time (checked, then imported).
        RateLimiter::for('compliance-imports', fn (Request $request) => Limit::perMinute(6)->by('compliance-imports:'.($request->user()?->getKey() ?? $request->ip())));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('compliance:expiry-reminders')->dailyAt('02:20')->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
            Route::middleware('web')->group($root.'/routes/web.php');
        }
    }
}
