<?php

namespace App\Platform\SupportAccess;

use App\Platform\SupportAccess\Console\ExpireSupportAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class SupportAccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Requests notify a client admin: a few per hour is plenty.
        RateLimiter::for('support-request', fn (Request $request) => Limit::perHour(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireSupportAccess::class]);
        }
    }
}
