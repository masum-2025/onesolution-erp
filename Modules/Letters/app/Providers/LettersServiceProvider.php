<?php

namespace Modules\Letters\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Letters\Export\LettersExporter;

/**
 * Letters: HR letters (appointment, increment, experience, warning...)
 * from designs a company makes or takes ready-made, filled with the
 * fields other modules give (LetterFieldSource, tag letters.field_sources:
 * HRM's employee, Payroll's pay), numbered and kept as issued.
 */
class LettersServiceProvider extends ServiceProvider
{
    /** Changes a person may send a minute (designs are saved as they are edited). */
    private const WRITES_PER_MINUTE = 60;

    /** Issues a person may send a minute (each up to Letters::MAX_PEOPLE letters). */
    private const ISSUES_PER_MINUTE = 10;

    public function register(): void
    {
        // The client's data export includes letters (ExportsModuleData).
        $this->app->tag([LettersExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'letters');

        RateLimiter::for('letters-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('letters-writes:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('letters-issue', fn (Request $request) => Limit::perMinute(self::ISSUES_PER_MINUTE)
            ->by('letters-issue:'.($request->user()?->getKey() ?? $request->ip())));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
            Route::middleware('web')->group($root.'/routes/web.php');
        }
    }
}
