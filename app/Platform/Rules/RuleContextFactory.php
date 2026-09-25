<?php

namespace App\Platform\Rules;

use App\Models\User;
use App\Platform\Access\AccessResolver;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Collection;

/**
 * Builds resolution chains. Level ids always come from server-side data
 * (the tenant context or loaded models), never from request input.
 */
class RuleContextFactory
{
    public function platform(): RuleContext
    {
        return new RuleContext([$this->platformLevel()], null);
    }

    public function forPartner(Partner $partner): RuleContext
    {
        return new RuleContext(
            [$this->platformLevel(), new RuleLevel(RuleScope::Partner, $partner->getKey(), $partner->name)],
            null,
            partnerId: $partner->getKey(),
        );
    }

    public function forPlan(string $plan): RuleContext
    {
        return new RuleContext([$this->platformLevel(), new RuleLevel(RuleScope::Plan, $plan, $plan)], null);
    }

    /**
     * @param  list<string>  $roleIds  Roles of the acting membership (Phase 4), in a stable order.
     * @param  Collection<int, Organization>|null  $ancestors  Root first; loaded when null.
     */
    public function forOrganization(
        Organization $organization,
        array $roleIds = [],
        ?User $user = null,
        ?Collection $ancestors = null,
    ): RuleContext {
        $ancestors ??= Organization::query()
            ->whereKey($organization->ancestorIds())
            ->orderBy('depth')
            ->get()
            ->toBase();

        $chain = $ancestors->concat([$organization])->values();
        $nearest = fn (string $column) => $chain->reverse()->first(fn (Organization $node) => $node->{$column} !== null)?->{$column};

        $plan = $nearest('plan_key') ?? config('tenancy.defaults.plan_key');
        $partner = $organization->relationLoaded('partner') ? $organization->partner : $organization->partner()->first();

        $levels = [
            $this->platformLevel(),
            new RuleLevel(RuleScope::Partner, $organization->partner_id, $partner?->name),
            new RuleLevel(RuleScope::Plan, $plan, $plan),
        ];

        foreach ($chain as $node) {
            $levels[] = new RuleLevel(RuleScope::forOrganizationType($node->type), $node->getKey(), $node->displayName());
        }

        foreach ($roleIds as $roleId) {
            $levels[] = new RuleLevel(RuleScope::Role, $roleId);
        }

        if ($user !== null) {
            $levels[] = new RuleLevel(RuleScope::User, $user->getKey(), $user->name);
        }

        return new RuleContext(
            $levels,
            $nearest('country_code') ?? config('tenancy.defaults.country_code'),
            partnerId: $organization->partner_id,
            rootOrganizationId: $organization->root_id,
        );
    }

    /**
     * The chain for whoever is acting now: organization member, partner
     * console user, or (no tenant) the platform level only.
     */
    public function current(): RuleContext
    {
        $context = app(CurrentContext::class);

        if ($context->hasOrganization()) {
            return $this->forOrganization(
                $context->organization(),
                app(AccessResolver::class)->roleIds(),
                $context->user(),
                $context->ancestors(),
            );
        }

        if ($context->hasPartnerConsole()) {
            return $this->forPartner($context->partner());
        }

        return $this->platform();
    }

    private function platformLevel(): RuleLevel
    {
        return new RuleLevel(RuleScope::Platform, null, 'Platform');
    }
}
