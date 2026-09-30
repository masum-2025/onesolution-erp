<?php

namespace App\Platform\Monitoring;

use App\Platform\Monitoring\Console\HealthCheck;
use App\Platform\Monitoring\Console\Heartbeat;
use App\Platform\Monitoring\Console\RevokeAccess;
use App\Platform\Monitoring\Console\SecurityAlerts;
use App\Platform\Security\Events\SecurityEventRecorded;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Monitoring and incident response (Phase 9-2): security alerts, the health
 * report, and the revocation command for incidents.
 */
class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Metrics::class);
    }

    public function boot(): void
    {
        Event::listen(SecurityEventRecorded::class, AlertDetector::class);

        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(30)->by('health:'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([HealthCheck::class, Heartbeat::class, SecurityAlerts::class, RevokeAccess::class]);
        }
    }
}
