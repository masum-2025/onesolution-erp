<?php

namespace Modules\Education\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Crm\Events\DealWon;
use Modules\Education\Export\EducationExporter;
use Modules\Education\Listeners\ApplicationFromCrm;

/**
 * Education: schools, colleges, universities, madrasas and coaching
 * centres. Structure, students, guardians, admissions and enrollments; the
 * student in the client's portal; its data in the client's export.
 */
class EducationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([EducationExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'education');

        RateLimiter::for('education-import', fn (Request $request) => Limit::perMinute(10)->by('education-import:'.($request->user()?->getKey() ?? $request->ip())));

        // Won admission deals become applications (CRM may be missing: then nothing listens).
        if (class_exists(DealWon::class)) {
            Event::listen(DealWon::class, ApplicationFromCrm::class);
        }

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
            Route::middleware('web')->group($root.'/routes/web.php');
        }
    }
}
