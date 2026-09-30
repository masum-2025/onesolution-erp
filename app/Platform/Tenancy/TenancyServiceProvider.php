<?php

namespace App\Platform\Tenancy;

use App\Models\User;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Contracts\SignInRequirements;
use App\Platform\Tenancy\Contracts\WorkspaceRestrictions;
use App\Platform\Tenancy\Databases\Console\TenantsList;
use App\Platform\Tenancy\Databases\Console\TenantsMigrate;
use App\Platform\Tenancy\Databases\Console\TenantsPlace;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Databases\TenantTables;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Policies\OrganizationPolicy;
use Carbon\CarbonInterface;
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

        // Where business data lives (Phase 10): placements read fresh per request / job.
        $this->app->scoped(TenantDatabases::class);

        // Nothing limits an account until another part says so (Billing binds its own).
        $this->app->bindIf(WorkspaceRestrictions::class, fn () => new class implements WorkspaceRestrictions
        {
            public function readOnlyReason(Organization $root): ?string
            {
                return null;
            }
        });

        // Nothing is required to sign in until Identity says so (two-step sign-in, Phase 8-1).
        $this->app->bindIf(SignInRequirements::class, fn () => new class implements SignInRequirements
        {
            public function check(User $user, CurrentContext $context): void {}

            public function setupDueAt(): ?CarbonInterface
            {
                return null;
            }
        });
    }

    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);

        // Dedicated / regional databases from the environment, and the tenant
        // tables, which the main database has too (it holds every shared client).
        TenantDatabases::registerConfigured();
        $this->loadMigrationsFrom(app(TenantTables::class)->migrationPaths());

        if ($this->app->runningInConsole()) {
            $this->commands([TenantsMigrate::class, TenantsPlace::class, TenantsList::class]);
        }

        RateLimiter::for('tenancy-login', fn (Request $request) => Limit::perMinute((int) config('tenancy.throttle.login'))
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('tenancy-sensitive', fn (Request $request) => Limit::perMinute((int) config('tenancy.throttle.sensitive'))
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
