<?php

namespace Modules\EducationFees\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\CourseRegistration\Events\RegistrationApproved;
use Modules\Education\Events\StudentAdmitted;
use Modules\EducationFees\Console\ApplyLateFines;
use Modules\EducationFees\Console\BillMonthAutomatically;
use Modules\EducationFees\Export\EducationFeesExporter;
use Modules\EducationFees\Listeners\BillCredits;
use Modules\EducationFees\Listeners\BillOnAdmission;

/**
 * Student fees: heads, structures, concessions, billing runs, bills and late
 * fines. Reads Education through its AcademicDirectory; tax rates through
 * Accounting's TaxCodes when the institution keeps books.
 */
class EducationFeesServiceProvider extends ServiceProvider
{
    /** Receipts one person at a counter may take a minute (a queue of parents at the start of the month). */
    private const COUNTER_WRITES_PER_MINUTE = 120;

    public function register(): void
    {
        $this->app->tag([EducationFeesExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'education_fees');

        Event::listen(StudentAdmitted::class, BillOnAdmission::class);
        // Per-credit fees follow approved course registrations (when that module is installed).
        if (class_exists(RegistrationApproved::class)) {
            Event::listen(RegistrationApproved::class, BillCredits::class);
        }

        RateLimiter::for('education-fees-counter', fn (Request $request) => Limit::perMinute(self::COUNTER_WRITES_PER_MINUTE)
            ->by('education-fees-counter:'.($request->user()?->getKey() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([ApplyLateFines::class, BillMonthAutomatically::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Each institution's own "today"; running twice the same day adds nothing.
            $schedule->command('education-fees:auto-bill')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
            $schedule->command('education-fees:apply-fines')->dailyAt('02:50')->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
