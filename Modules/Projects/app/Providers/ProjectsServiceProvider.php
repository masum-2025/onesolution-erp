<?php

namespace Modules\Projects\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Projects\Console\TellOverdueTasks;
use Modules\Projects\Export\ProjectsExporter;
use Modules\Projects\Services\People;

/**
 * Projects: a company's or branch's projects for its clients, with tasks
 * for one or more people, templates of the usual steps and everyone's own
 * task list. Works on its own; CRM (clients) is used when it is on.
 */
class ProjectsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request: who may be given a task is read once.
        $this->app->scoped(People::class);
        // The client's data export includes its projects (ExportsModuleData).
        $this->app->tag([ProjectsExporter::class], 'module.exporters');
        $this->commands([TellOverdueTasks::class]);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'projects');

        RateLimiter::for('projects-writes', fn (Request $request) => Limit::perMinute(120)->by('projects-writes:'.($request->user()?->getKey() ?? $request->ip())));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('projects:overdue-tasks')->dailyAt('02:40')->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
