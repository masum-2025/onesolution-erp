<?php

namespace App\Platform\Rules;

use App\Models\User;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Rules\Console\ExplainRule;
use App\Platform\Rules\Console\SetRuleValue;
use App\Platform\Rules\Console\SyncRuleDefinitions;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class RulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuleValueValidator::class);
        $this->app->singleton(RuleCache::class);
        $this->app->singleton(RuleContextFactory::class);
        $this->app->singleton(RuleTargets::class);
        $this->app->singleton(RuleCatalog::class, fn ($app) => RuleCatalog::fromModules(
            $app->make(ModuleRegistry::class),
            $app->make(RuleValueValidator::class),
        ));

        // Per-request memo of resolved maps.
        $this->app->scoped(RuleResolver::class);
    }

    public function boot(): void
    {
        // Interim until Phase 4 permissions (rules.edit.{module}): an owner membership
        // in the active context may edit and approve rules of visible organizations.
        Gate::define('rules.manage', function (User $user, Organization $organization) {
            $context = $this->app->make(CurrentContext::class);

            return $context->hasOrganization() && $context->user()?->is($user) && $context->membership()->isOwner()
                ? Response::allow()
                : Response::deny(__('tenancy.errors.forbidden'));
        });

        // Tree, plan or country changes can change every resolved value in the tree.
        Organization::saved(function (Organization $organization) {
            $cache = $this->app->make(RuleCache::class);
            $cache->flushTree($organization->root_id);

            if ($organization->getOriginal('root_id') !== $organization->root_id) {
                $cache->flushTree($organization->getOriginal('root_id'));
            }
        });

        Partner::saved(fn (Partner $partner) => $this->app->make(RuleCache::class)->flush(RuleScope::Partner, $partner->getKey()));

        if ($this->app->runningInConsole()) {
            $this->commands([SyncRuleDefinitions::class, SetRuleValue::class, ExplainRule::class]);
        }
    }
}
