<?php

namespace App\Platform\Offline;

use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Offline\Console\DiscardExpiredQuarantine;
use App\Platform\Offline\Listeners\WipeDevicesWhenOfflineOff;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Offline mode and secure sync (Phase 7): devices, signed leases, the sync
 * pipeline, held changes and remote wipe. What can be changed offline comes
 * from module manifests ("sync_records").
 */
class OfflineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SyncRecords::class);
    }

    public function boot(): void
    {
        Event::listen(ModuleDisabled::class, WipeDevicesWhenOfflineOff::class);

        // A device syncs every little while when online; this only stops floods.
        RateLimiter::for('offline-sync', fn (Request $request) => Limit::perMinute(30)->by('offline-sync:'.($request->user()?->getKey() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([DiscardExpiredQuarantine::class]);
        }
    }
}
