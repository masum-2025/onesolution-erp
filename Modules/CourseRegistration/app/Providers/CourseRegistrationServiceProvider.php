<?php

namespace Modules\CourseRegistration\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\CourseRegistration\Events\SeatOffered;
use Modules\CourseRegistration\Export\CourseRegistrationExporter;
use Modules\CourseRegistration\Listeners\NotifySeatOffered;

/**
 * Course registration: subjects offered each session, the registration
 * window, students' registrations (staff and the student in the portal),
 * waiting lists and outcomes. Reads Education through its AcademicDirectory.
 */
class CourseRegistrationServiceProvider extends ServiceProvider
{
    /** Portal writes a student may send a minute (adding, dropping, handing in). */
    private const PORTAL_WRITES_PER_MINUTE = 20;

    /** Registration writes a staff member may send a minute (adding subjects for many students). */
    private const STAFF_WRITES_PER_MINUTE = 120;

    public function register(): void
    {
        $this->app->tag([CourseRegistrationExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'course_registration');

        RateLimiter::for('course-registration-portal', fn (Request $request) => Limit::perMinute(self::PORTAL_WRITES_PER_MINUTE)
            ->by('course-registration-portal:'.($request->user()?->getKey() ?? $request->ip())));

        RateLimiter::for('course-registration-staff', fn (Request $request) => Limit::perMinute(self::STAFF_WRITES_PER_MINUTE)
            ->by('course-registration-staff:'.($request->user()?->getKey() ?? $request->ip())));

        Event::listen(SeatOffered::class, NotifySeatOffered::class);

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
