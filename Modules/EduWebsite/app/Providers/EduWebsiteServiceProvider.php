<?php

namespace Modules\EduWebsite\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\EduWebsite\Console\PruneEnquiries;
use Modules\EduWebsite\Export\WebsiteExporter;
use Modules\EduWebsite\Http\Middleware\ServeSiteByHost;

/**
 * A school's public website: a template, pages built from blocks, a notice
 * board, pictures and files, served lightly (server-built pages, styles
 * inline, no scripts) at "{slug}.{sites domain}" and "/s/{slug}". Programs
 * come from Education, today's attendance numbers from Attendance and
 * StudentAttendance (their public services), when those are on.
 */
class EduWebsiteServiceProvider extends ServiceProvider
{
    /** Changes a person may send a minute. */
    private const WRITES_PER_MINUTE = 60;

    /** Files a person may send a minute (each is read and drawn again). */
    private const UPLOADS_PER_MINUTE = 10;

    /** Address checks a person may make a minute (while typing an address). */
    private const ADDRESS_CHECKS_PER_MINUTE = 30;

    /** Admission enquiries one visitor (address) may send an hour (the controller counts the same for a site's own host). */
    private const ENQUIRIES_PER_HOUR = 5;

    /** Enquiry lists a person may download a minute (personal details). */
    private const EXPORTS_PER_MINUTE = 5;

    public function register(): void
    {
        // The client's data export includes the website (ExportsModuleData).
        $this->app->tag([WebsiteExporter::class], 'module.exporters');
        $this->commands([PruneEnquiries::class]);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'edu_website');
        $this->loadViewsFrom($root.'/resources/views', 'edu_website');

        RateLimiter::for('edu-website-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('edu-website-writes:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('edu-website-address', fn (Request $request) => Limit::perMinute(self::ADDRESS_CHECKS_PER_MINUTE)
            ->by('edu-website-address:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('edu-website-export', fn (Request $request) => Limit::perMinute(self::EXPORTS_PER_MINUTE)
            ->by('edu-website-export:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('edu-website-enquiry', fn (Request $request) => Limit::perHour(self::ENQUIRIES_PER_HOUR)
            ->by('edu-website-enquiry-route:'.$request->ip())->response(fn () => response('', 429)));
        RateLimiter::for('edu-website-uploads', fn (Request $request) => Limit::perMinute(self::UPLOADS_PER_MINUTE)
            ->by('edu-website-uploads:'.($request->user()?->getKey() ?? $request->ip())));

        // "{slug}.{sites domain}" is answered before anything else of the app runs (even before
        // ResolveHost, which refuses hosts that are not the platform's or a partner's).
        $this->app->make(Kernel::class)->prependMiddleware(ServeSiteByHost::class);

        // Enquiries past their keeping time go every night.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('edu-website:prune-enquiries')->dailyAt('03:40')->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
            Route::middleware('web')->group($root.'/routes/web.php');
        }
    }
}
