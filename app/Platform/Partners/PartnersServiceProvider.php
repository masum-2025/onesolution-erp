<?php

namespace App\Platform\Partners;

use App\Platform\Partners\Console\SetPartnerStatus;
use App\Platform\Partners\Contracts\DnsTxtLookup;
use App\Platform\Partners\Services\SystemDnsTxtLookup;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class PartnersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The address of this request; reset between requests and jobs (jobs act as platform).
        $this->app->scoped(HostContext::class);

        $this->app->bind(DnsTxtLookup::class, SystemDnsTxtLookup::class);
    }

    public function boot(): void
    {
        // DNS checks and uploads are slow or large: fewer per minute than other changes.
        RateLimiter::for('partner-heavy', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([SetPartnerStatus::class]);
        }
    }
}
