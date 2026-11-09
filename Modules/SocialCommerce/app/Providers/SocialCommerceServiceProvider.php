<?php

namespace Modules\SocialCommerce\Providers;

use App\Platform\Meta\Events\MetaAppChanged;
use App\Platform\Meta\Events\MetaPersonRemovedApp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\SocialCommerce\Console\PublishDuePosts;
use Modules\SocialCommerce\Export\SocialCommerceExporter;
use Modules\SocialCommerce\Listeners\StopPagesOfMetaApp;

/**
 * Facebook selling (SC-1): a company's pages connected through its
 * partner's Meta app (platform Meta), product posts written in the ERP,
 * approved and put on Facebook at their time. Products come from
 * Inventory's Stock.
 */
class SocialCommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The client's data export includes pages (no tokens) and posts (ExportsModuleData).
        $this->app->tag([SocialCommerceExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'social_commerce');

        $by = fn (string $name) => fn (Request $request) => $name.':'.($request->user()?->getKey() ?? $request->ip());
        RateLimiter::for('social-writes', fn (Request $request) => Limit::perMinute(60)->by($by('social-writes')($request)));
        RateLimiter::for('social-uploads', fn (Request $request) => Limit::perMinute(30)->by($by('social-uploads')($request)));
        // Each start asks Meta; the callback comes from browsers coming back.
        RateLimiter::for('social-connect', fn (Request $request) => Limit::perMinute(10)->by($by('social-connect')($request)));

        Event::listen([MetaAppChanged::class, MetaPersonRemovedApp::class], StopPagesOfMetaApp::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PublishDuePosts::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('social:publish-due')->everyMinute()->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
            Route::middleware('web')->group($root.'/routes/web.php');
        }
    }
}
