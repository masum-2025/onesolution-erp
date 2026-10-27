<?php

namespace Modules\Appraisals\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Appraisals\Console\RemindReviews;
use Modules\Appraisals\Export\AppraisalsExporter;

/**
 * Appraisals: review cycles of a company's people (HRM's employees and
 * their managers, read through its EmployeeDirectory), goals, self and
 * manager reviews, scores and bands from rules.
 */
class AppraisalsServiceProvider extends ServiceProvider
{
    /** Changes a person may send a minute (ratings are saved as they are written). */
    private const WRITES_PER_MINUTE = 60;

    public function register(): void
    {
        // The client's data export includes appraisals (ExportsModuleData).
        $this->app->tag([AppraisalsExporter::class], 'module.exporters');
        $this->commands([RemindReviews::class]);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'appraisals');

        RateLimiter::for('appraisals-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('appraisals-writes:'.($request->user()?->getKey() ?? $request->ip())));

        // Whoever must act on a review is reminded before its step's last day (each morning).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('appraisals:remind')->dailyAt('08:10')->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
