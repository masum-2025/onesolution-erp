<?php

namespace Modules\Recruitment\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Recruitment\Console\ExpireOffers;
use Modules\Recruitment\Console\ForgetCandidates;
use Modules\Recruitment\Export\RecruitmentExporter;

/**
 * Recruitment: job openings at a company's units (HRM's units and
 * positions, read through its EmployeeDirectory), applications from a
 * public careers page or added by hand, interviews with feedback, and
 * hiring into HRM through its EmployeeLifecycle.
 */
class RecruitmentServiceProvider extends ServiceProvider
{
    /** Changes a person may send a minute. */
    private const WRITES_PER_MINUTE = 30;

    /** CVs a person may add a minute (each is read and kept). */
    private const UPLOADS_PER_MINUTE = 10;

    /** Applications one visitor (address) may send an hour (the controller counts the same). */
    private const APPLIES_PER_HOUR = 10;

    public function register(): void
    {
        // The client's data export includes recruitment (ExportsModuleData).
        $this->app->tag([RecruitmentExporter::class], 'module.exporters');
        $this->commands([ForgetCandidates::class, ExpireOffers::class]);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'recruitment');
        $this->loadViewsFrom($root.'/resources/views', 'recruitment');

        RateLimiter::for('recruitment-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('recruitment-writes:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('recruitment-uploads', fn (Request $request) => Limit::perMinute(self::UPLOADS_PER_MINUTE)
            ->by('recruitment-uploads:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('recruitment-apply', fn (Request $request) => Limit::perHour(self::APPLIES_PER_HOUR)
            ->by('recruitment-apply-route:'.$request->ip())->response(fn () => response('', 429)));

        // People not taken (and never confirmed) are forgotten every night.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('recruitment:forget-candidates')->dailyAt('03:50')->withoutOverlapping()->onOneServer();
            // Offers not answered by their day expire (hourly: each company's day ends at its own time).
            $schedule->command('recruitment:expire-offers')->hourlyAt(5)->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
            Route::middleware('web')->group($root.'/routes/web.php');
        }
    }
}
