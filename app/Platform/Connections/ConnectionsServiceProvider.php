<?php

namespace App\Platform\Connections;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Business connections (NET-1): a service provider and its client, two
 * clients of the platform, working together through messages. The switch,
 * rules and texts are the business_network module; the message kinds come
 * from the modules that receive them.
 */
class ConnectionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConnectionKinds::class);
    }

    public function boot(): void
    {
        // Looking up and using invitation codes: enough for typos, far too few to guess one.
        RateLimiter::for('connection-codes', fn (Request $request) => [
            Limit::perMinute(10)->by('connection-codes:'.$request->ip()),
            Limit::perHour(30)->by('connection-codes:user:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);
    }
}
