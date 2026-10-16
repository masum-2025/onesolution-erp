<?php

namespace Modules\TeacherFeedback\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Teacher feedback: rounds in which students or guardians rate the
 * teachers who teach them, anonymously. Reads Education through its
 * AcademicDirectory; rating happens in the client's portal.
 */
class TeacherFeedbackServiceProvider extends ServiceProvider
{
    /** Ratings a portal member may send a minute (one per teacher; a few teachers a minute is plenty). */
    private const RATINGS_PER_MINUTE = 20;

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'teacher_feedback');

        RateLimiter::for('teacher-feedback-portal', fn (Request $request) => Limit::perMinute(self::RATINGS_PER_MINUTE)
            ->by('teacher-feedback-portal:'.($request->user()?->getKey() ?? $request->ip())));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
