<?php

namespace App\Platform\Modules;

use App\Platform\Modules\Enums\ModuleState;
use App\Platform\Modules\Enums\ResolutionReason;
use App\Platform\Modules\Models\ModuleConsent;
use App\Platform\Modules\Models\OrganizationModule;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Partners\Models\PartnerModule;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\HierarchyService;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Support\Collection;

/**
 * Decides whether a module is enabled for an organization.
 *
 *  1. Available: the organization's plan (and the partner plan the client is
 *     on, which may leave modules out) and sector allow it, and consent
 *     exists when the module requires it.
 *  2. State: the topmost ancestor lock wins; otherwise the nearest level that
 *     is not "inherit"; otherwise disabled (core modules: enabled).
 *  3. Every required module must itself resolve enabled.
 */
class ModuleResolver
{
    /** @var array<string, array<string, ResolvedModule>> */
    private array $memo = [];

    public function __construct(
        private ModuleRegistry $registry,
        private HierarchyService $hierarchy,
        private OrganizationSettingsResolver $settings,
        private ModuleCache $cache,
        private PlanCatalog $plans,
        private RuleResolver $rules,
        private RuleContextFactory $ruleContexts,
    ) {}

    public function isEnabled(string $key, Organization $organization): bool
    {
        return $this->resolve($key, $organization)->enabled;
    }

    /**
     * For code running inside a request or job with a tenant context.
     */
    public function isEnabledInContext(string $key): bool
    {
        return $this->isEnabled($key, app(CurrentContext::class)->organization());
    }

    public function resolve(string $key, Organization $organization): ResolvedModule
    {
        $this->registry->get($key);

        return $this->resolveAll($organization)[$key];
    }

    /**
     * @return array<string, ResolvedModule>
     */
    public function resolveAll(Organization $organization): array
    {
        $memoKey = $organization->getKey().':'.$this->cache->version($organization->root_id);

        return $this->memo[$memoKey] ??= array_map(
            fn (array $data) => ResolvedModule::fromArray($data),
            $this->cache->remember(
                $organization->getKey(),
                $organization->root_id,
                fn () => array_map(fn (ResolvedModule $m) => $m->toArray(), $this->compute($organization)),
            ),
        );
    }

    /**
     * Resolve straight from the database, skipping every cache. Used before
     * and after a change, where stale data would give wrong answers.
     *
     * @return array<string, ResolvedModule>
     */
    public function fresh(Organization $organization): array
    {
        return $this->compute($organization);
    }

    /**
     * @return array<string, ResolvedModule>
     */
    private function compute(Organization $organization): array
    {
        $ancestors = $this->hierarchy->ancestors($organization);
        /** @var Collection<int, Organization> $chain Root first, organization last. */
        $chain = $ancestors->concat([$organization])->values();
        $chainIds = $chain->map(fn (Organization $node) => $node->getKey())->all();

        $plan = $this->settings->values($organization, $ancestors)['plan_key'];
        $sector = $chain->reverse()->first(fn (Organization $node) => $node->sector_key !== null)?->sector_key;
        // A partner plan narrows the base plan (null = everything the base plan has).
        $partnerPlanModules = PartnerPlan::query()
            ->whereIn('id', Subscription::query()->select('partner_plan_id')->where('organization_id', $chain->first()->getKey()))
            ->value('modules');

        $rows = OrganizationModule::query()
            ->whereIn('organization_id', $chainIds)
            ->get()
            ->groupBy('module_key');

        $consented = ModuleConsent::query()
            ->active()
            ->whereIn('organization_id', $chainIds)
            ->pluck('module_key')
            ->flip();

        // The partner's settings for all its clients sit above the whole chain;
        // the platform decides which modules a partner may offer at all.
        $partnerRows = PartnerModule::query()
            ->where('partner_id', $organization->partner_id)
            ->where('state', '!=', ModuleState::Inherit)
            ->get()
            ->keyBy('module_key');
        $offered = $organization->partner === null ? null
            : $this->rules->get('partners.allowed_modules', $this->ruleContexts->forPartner($organization->partner));

        $resolved = [];

        foreach ($this->registry->all() as $key => $module) {
            /** @var Collection<string, OrganizationModule> $byOrganization */
            $byOrganization = ($rows[$key] ?? new Collection)->keyBy('organization_id');
            $partnerRow = $partnerRows->get($key);
            $partnerLock = $partnerRow !== null && $partnerRow->locked;

            // Topmost lock in the chain decides for everything below it (a partner lock is above all).
            $lockRow = null;
            foreach ($partnerLock ? [] : $chainIds as $id) {
                $row = $byOrganization->get($id);
                if ($row !== null && $row->locked && $row->state !== ModuleState::Inherit) {
                    $lockRow = $row;
                    break;
                }
            }

            $decidingRow = $partnerLock ? null : ($lockRow ?? $chain->reverse()
                ->map(fn (Organization $node) => $byOrganization->get($node->getKey()))
                ->first(fn (?OrganizationModule $row) => $row !== null && $row->state !== ModuleState::Inherit));

            // Without a setting in the chain, the partner's default applies.
            $fromPartner = $partnerLock || ($decidingRow === null && $partnerRow !== null);

            $state = $fromPartner ? $partnerRow->state : ($decidingRow?->state ?? ($module->isCore ? ModuleState::Enabled : ModuleState::Disabled));
            $lockedByAncestor = $lockRow !== null && $lockRow->organization_id !== $organization->getKey();

            $blockedBy = array_values(array_filter(
                $module->requires,
                fn (string $required) => ! $resolved[$required]->enabled,
            ));

            $reason = match (true) {
                // The plan must include the module, and the module must allow the plan.
                ! $module->allowsPlan($plan) || ! $this->plans->includes($plan, $key) => ResolutionReason::NotInPlan,
                ! $module->isCore && is_array($partnerPlanModules) && ! in_array($key, $partnerPlanModules, true) => ResolutionReason::NotInPlan,
                ! $module->isCore && is_array($offered) && ! in_array($key, $offered, true) => ResolutionReason::NotOffered,
                ! $module->allowsSector($sector) => ResolutionReason::SectorNotAllowed,
                $module->requiresConsent && ! $consented->has($key) => ResolutionReason::ConsentMissing,
                $state === ModuleState::Disabled && ($lockRow !== null || $partnerLock) => ResolutionReason::LockedDisabled,
                $state === ModuleState::Disabled && $decidingRow === null && ! $fromPartner => ResolutionReason::NotEnabled,
                $state === ModuleState::Disabled => ResolutionReason::Disabled,
                $blockedBy !== [] => ResolutionReason::DependencyDisabled,
                default => ResolutionReason::Enabled,
            };

            $resolved[$key] = new ResolvedModule(
                key: $key,
                enabled: $reason === ResolutionReason::Enabled,
                available: ! in_array($reason, [ResolutionReason::NotInPlan, ResolutionReason::NotOffered, ResolutionReason::SectorNotAllowed, ResolutionReason::ConsentMissing], true),
                reason: $reason,
                state: $state,
                source: match (true) {
                    $fromPartner => 'partner',
                    $decidingRow === null => 'default',
                    $decidingRow->organization_id === $organization->getKey() => 'self',
                    default => 'inherited',
                },
                sourceOrganizationId: $decidingRow?->organization_id,
                lockedByOrganizationId: $lockedByAncestor ? $lockRow->organization_id : null,
                lockedHere: (bool) $byOrganization->get($organization->getKey())?->locked,
                blockedBy: $blockedBy,
                lockedByPartner: $partnerLock,
            );
        }

        return $resolved;
    }
}
