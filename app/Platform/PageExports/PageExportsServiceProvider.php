<?php

namespace App\Platform\PageExports;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** Export and print on module pages: a person exports a page at most 30 times a minute. */
class PageExportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('page-export', fn (Request $request) => Limit::perMinute(30)
            ->by('page-export:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
