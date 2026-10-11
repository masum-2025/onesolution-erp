<?php

namespace Modules\EducationExams\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\EducationExams\Export\EducationExamsExporter;

/**
 * Exams: grading schemes, exams and papers, mark sheets entered by teachers
 * and approved by someone else. Reads Education through its AcademicDirectory.
 */
class EducationExamsServiceProvider extends ServiceProvider
{
    /** Mark saves one teacher may send a minute (a grid saved as they type). */
    private const MARK_WRITES_PER_MINUTE = 120;

    public function register(): void
    {
        $this->app->tag([EducationExamsExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'education_exams');

        RateLimiter::for('education-exams-marks', fn (Request $request) => Limit::perMinute(self::MARK_WRITES_PER_MINUTE)
            ->by('education-exams-marks:'.($request->user()?->getKey() ?? $request->ip())));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
