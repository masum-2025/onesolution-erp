<?php

namespace Modules\EducationLectures\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\EducationLectures\Events\LecturePublished;
use Modules\EducationLectures\Listeners\TellLecturePublished;

/**
 * Class lectures: teachers post notes, files and video links for the
 * subjects they teach; students and guardians read them in the portal.
 * Reads Education through its AcademicDirectory.
 */
class EducationLecturesServiceProvider extends ServiceProvider
{
    /** Lecture writes a person may send a minute (posting, files). */
    private const WRITES_PER_MINUTE = 60;

    /** Files a person may upload a minute. */
    private const UPLOADS_PER_MINUTE = 20;

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'education_lectures');

        RateLimiter::for('education-lectures-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('education-lectures-writes:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('education-lectures-uploads', fn (Request $request) => Limit::perMinute(self::UPLOADS_PER_MINUTE)
            ->by('education-lectures-uploads:'.($request->user()?->getKey() ?? $request->ip())));

        Event::listen(LecturePublished::class, TellLecturePublished::class);

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
            Route::middleware('web')->group($root.'/routes/web.php');
        }
    }
}
