<?php

namespace App\Platform\Access;

use App\Models\User;
use App\Platform\Access\Console\SyncAccess;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Rules\RuleCatalog;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionCatalog::class, fn ($app) => new PermissionCatalog(
            $app->make(ModuleRegistry::class),
            $app->make(RuleCatalog::class),
        ));

        // Per request / job: answers depend on the acting membership.
        $this->app->scoped(AccessResolver::class);
    }

    public function boot(): void
    {
        // Every catalog permission is a Gate ability: Gate::authorize('payroll.run', $organization)
        // or the "can:payroll.run" middleware. The target defaults to the current organization.
        Gate::before(function (User $user, string $ability, array $arguments) {
            $catalog = $this->app->make(PermissionCatalog::class);

            if (! $catalog->has($ability)) {
                return null;
            }

            $context = $this->app->make(CurrentContext::class);
            $target = ($arguments[0] ?? null) instanceof Organization
                ? $arguments[0]
                : ($context->hasOrganization() ? $context->organization() : null);

            $allowed = $target !== null
                && $context->user()?->is($user) === true
                && $this->app->make(AccessResolver::class)->allows($ability, $target);

            return $allowed ? Response::allow() : Response::deny(__('tenancy.errors.forbidden'));
        });

        if ($this->app->runningInConsole()) {
            $this->commands([SyncAccess::class]);
        }
    }
}
