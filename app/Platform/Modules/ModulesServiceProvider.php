<?php

namespace App\Platform\Modules;

use App\Models\User;
use App\Platform\Modules\Console\PurgeDueModuleData;
use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Modules\Listeners\RevokeIntegrationTokens;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, fn ($app) => ModuleRegistry::fromManifests(
            $app->make(ManifestLoader::class)->load(),
            config('plans.catalog'),
        ));

        $this->app->singleton(ModuleCache::class);

        // Per-request memo of resolved maps.
        $this->app->scoped(ModuleResolver::class);
    }

    public function boot(): void
    {
        // Interim until Phase 4 permissions: an owner membership in the active context.
        Gate::define('modules.manage', function (User $user, Organization $organization) {
            $context = $this->app->make(CurrentContext::class);

            return $context->hasOrganization()
                && $context->user()?->is($user)
                && $context->membership()->isOwner()
                    ? Response::allow()
                    : Response::deny(__('tenancy.errors.forbidden'));
        });

        Event::listen(ModuleDisabled::class, RevokeIntegrationTokens::class);

        // Plan, sector or tree position changes can change every resolved map in the tree.
        Organization::saved(function (Organization $organization) {
            $cache = $this->app->make(ModuleCache::class);
            $cache->flushTree($organization->root_id);

            if ($organization->getOriginal('root_id') !== $organization->root_id) {
                $cache->flushTree($organization->getOriginal('root_id'));
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeDueModuleData::class]);
        }
    }
}
