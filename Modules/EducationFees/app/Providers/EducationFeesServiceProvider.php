<?php

namespace Modules\EducationFees\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Education\Events\StudentAdmitted;
use Modules\EducationFees\Console\ApplyLateFines;
use Modules\EducationFees\Console\BillMonthAutomatically;
use Modules\EducationFees\Export\EducationFeesExporter;
use Modules\EducationFees\Listeners\BillOnAdmission;

/**
 * Student fees: heads, structures, concessions, billing runs, bills and late
 * fines. Reads Education through its AcademicDirectory; tax rates through
 * Accounting's TaxCodes when the institution keeps books.
 */
class EducationFeesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([EducationFeesExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'education_fees');

        Event::listen(StudentAdmitted::class, BillOnAdmission::class);

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
