<?php

namespace Modules\EduWebsite\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
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

    public function register(): void
    {
        // The client's data export includes the website (ExportsModuleData).
        $this->app->tag([WebsiteExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'edu_website');
        $this->loadViewsFrom($root.'/resources/views', 'edu_website');

        RateLimiter::for('edu-website-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('edu-website-writes:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('edu-website-uploads', fn (Request $request) => Limit::perMinute(self::UPLOADS_PER_MINUTE)
            ->by('edu-website-uploads:'.($request->user()?->getKey() ?? $request->ip())));

        // "{slug}.{sites domain}" is answered before anything else of the app runs (even before
        // ResolveHost, which refuses hosts that are not the platform's or a partner's).
        $this->app->make(Kernel::class)->prependMiddleware(ServeSiteByHost::class);

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
            Route::middleware('web')->group($root.'/routes/web.php');
        }
    }
}
