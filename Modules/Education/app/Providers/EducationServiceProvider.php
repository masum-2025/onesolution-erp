<?php

namespace Modules\Education\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Education\Export\EducationExporter;

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

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
            Route::middleware('web')->group($root.'/routes/web.php');
        }
    }
}
