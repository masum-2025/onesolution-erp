<?php

namespace Modules\Crm\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Crm\Console\RemindFollowUps;
use Modules\Crm\Export\CrmExporter;
use Modules\Crm\Listeners\RecordPosSale;
use Modules\Pos\Events\SaleMade;

/**
 * Customer relations: contacts, deals in pipelines, follow-ups, estimates
 * and quotations with the company's own fields. Listens to POS sales for
 * purchases and points; reminds people of follow-ups.
 */
class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([CrmExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'crm');

        RateLimiter::for('crm-export', fn (Request $request) => Limit::perMinute(5)->by('crm-export:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('crm-import', fn (Request $request) => Limit::perMinute(10)->by('crm-import:'.($request->user()?->getKey() ?? $request->ip())));

        if (class_exists(SaleMade::class)) {
            Event::listen(SaleMade::class, RecordPosSale::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RemindFollowUps::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('crm:remind')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
