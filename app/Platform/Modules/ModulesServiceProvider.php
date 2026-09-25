<?php

namespace App\Platform\Modules;

use App\Platform\Modules\Console\PurgeDueModuleData;
use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Modules\Listeners\RevokeIntegrationTokens;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, fn ($app) => ModuleRegistry::fromManifests(
            $app->make(ManifestLoader::class)->load(),
            $app->make(PlanCatalog::class)->keys(),
        ));

        $this->app->singleton(ModuleCache::class);

        // Per-request memo of resolved maps.
        $this->app->scoped(ModuleResolver::class);
    }

    public function boot(): void
    {
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
