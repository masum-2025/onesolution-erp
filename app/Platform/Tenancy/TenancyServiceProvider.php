<?php

namespace App\Platform\Tenancy;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Contracts\WorkspaceRestrictions;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Policies\OrganizationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One context per request / job; reset automatically between them.
        $this->app->scoped(CurrentContext::class);

        // Nothing limits an account until another part says so (Billing binds its own).
        $this->app->bindIf(WorkspaceRestrictions::class, fn () => new class implements WorkspaceRestrictions
        {
            public function readOnlyReason(Organization $root): ?string
            {
                return null;
            }
        });
    }

    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);

        RateLimiter::for('tenancy-login', fn (Request $request) => Limit::perMinute((int) config('tenancy.throttle.login'))
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('tenancy-sensitive', fn (Request $request) => Limit::perMinute((int) config('tenancy.throttle.sensitive'))
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
