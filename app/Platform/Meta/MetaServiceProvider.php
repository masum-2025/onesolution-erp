<?php

namespace App\Platform\Meta;

use App\Platform\Meta\Console\ApplyMetaAppChanges;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Meta (SC-1): partners' own Meta apps, the Graph API client, and Meta's
 * callbacks (a person removed the app, asked for their data to go).
 */
class MetaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Each change is checked with Meta and asks for the password.
        RateLimiter::for('meta-app', fn (Request $request) => Limit::perMinute(6)->by('meta-app:'.($request->user()?->getKey() ?? $request->ip())));
        // Meta's callbacks come from Meta's addresses; this only stops floods.
        RateLimiter::for('meta-callback', fn (Request $request) => Limit::perMinute(120)->by('meta-callback:'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([ApplyMetaAppChanges::class]);
        }
    }
}
