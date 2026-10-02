<?php

namespace Modules\Hrm\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Hrm\Console\PruneImports;
use Modules\Hrm\Export\HrmExporter;

/**
 * Human resources: positions, employees, employment history and documents.
 * Business data lives in the client's database (tenant tables); other
 * modules hear about changes through Modules\Hrm\Events\EmploymentChanged.
 */
class HrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The client's data export includes HRM (ExportsModuleData).
        $this->app->tag([HrmExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'hrm');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneImports::class]);
        }
        // Employee details of unstarted imports do not linger (HRM-3a).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('hrm:prune-imports')->dailyAt('03:50')->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
            Route::middleware('web')->group($root.'/routes/web.php');
        }
    }
}
