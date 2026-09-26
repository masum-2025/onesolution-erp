<?php

namespace App\Platform\DataExport;

use App\Platform\DataExport\Console\PruneDataExports;
use App\Platform\DataExport\Services\ExportBuilder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class DataExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Modules hand over their own data (ExportsModuleData, tag "module.exporters").
        $this->app->when(ExportBuilder::class)->needs('$exporters')->giveTagged('module.exporters');
    }

    public function boot(): void
    {
        // A full export is heavy: a few per hour per person.
        RateLimiter::for('data-export', fn (Request $request) => Limit::perHour(5)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([PruneDataExports::class]);
        }
    }
}
