<?php

namespace Modules\StudentAttendance\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\EducationExams\Contracts\StudentAttendance;
use Modules\StudentAttendance\Events\SheetSaved;
use Modules\StudentAttendance\Exams\ReportCardAttendance;
use Modules\StudentAttendance\Listeners\TellAbsences;
use Modules\StudentAttendance\Setup\AttendanceSetupSteps;

/**
 * Students' attendance: a sheet a day per section (class teacher) or a
 * sheet per period (subject teacher, rule student_attendance.by_period).
 * Reads Education through its AcademicDirectory; gives Exams the days
 * attended for report cards (its StudentAttendance contract).
 */
class StudentAttendanceServiceProvider extends ServiceProvider
{
    /** Attendance writes a person may send a minute (a teacher saves many sheets in a row). */
    private const WRITES_PER_MINUTE = 60;

    /** Month registers (and CSV downloads) a person may ask for a minute. */
    private const EXPORTS_PER_MINUTE = 30;

    public function register(): void
    {
        // Report cards show "days attended" from here; nothing when this module is off (checked inside).
        if (interface_exists(StudentAttendance::class)) {
            $this->app->bind(StudentAttendance::class, ReportCardAttendance::class);
        }
        // Its steps on Education's setup checklist.
        $this->app->tag([AttendanceSetupSteps::class], 'education.setup_steps');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'student_attendance');

        RateLimiter::for('student-attendance-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('student-attendance-writes:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('student-attendance-exports', fn (Request $request) => Limit::perMinute(self::EXPORTS_PER_MINUTE)
            ->by('student-attendance-exports:'.($request->user()?->getKey() ?? $request->ip())));

        Event::listen(SheetSaved::class, TellAbsences::class);

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
