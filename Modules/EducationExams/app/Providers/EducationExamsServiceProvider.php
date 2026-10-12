<?php

namespace Modules\EducationExams\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\EducationExams\Events\ResultPublished;
use Modules\EducationExams\Export\EducationExamsExporter;
use Modules\EducationExams\Listeners\TellResultPublished;

/**
 * Exams: grading schemes, exams and papers, mark sheets entered by teachers
 * and approved by someone else, results, report cards and the family portal.
 * Reads Education through its AcademicDirectory (and Fees through its FeeDirectory).
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

        // Looking students up for a transcript: a person, not a scraper.
        RateLimiter::for('education-exams-search', fn (Request $request) => Limit::perMinute(60)
            ->by('education-exams-search:'.($request->user()?->getKey() ?? $request->ip())));

        // Families are told published results (rule education_exams.notify_results).
        Event::listen(ResultPublished::class, TellResultPublished::class);

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
