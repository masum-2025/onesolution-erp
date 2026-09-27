<?php

namespace App\Platform\Portal;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * B2B2C portals (Phase 5C-4): a client's own people see only the records
 * linked to them. The switch, rules and texts are the client_portal module;
 * the record kinds come from the modules that own the records.
 */
class PortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PortalSubjects::class);
        // Per request / job, like the context it reads.
        $this->app->scoped(PortalAccess::class);
    }

    public function boot(): void
    {
        // Looking up and using invitation codes: enough for typos, far too few to guess one.
        RateLimiter::for('portal-join', fn (Request $request) => Limit::perMinute(10)->by('portal-join:'.$request->ip()));
    }
}
