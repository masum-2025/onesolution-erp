<?php

namespace App\Platform\Packaging\Actions;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Modules\Events\ModuleEnabled;
use App\Platform\Modules\ModuleCache;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Packaging\Exceptions\PackagingException;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Packaging\Services\UsageLimiter;
use App\Platform\Rules\RuleCache;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves a subscription (a top organization and everything under it) to
 * another plan, or a partner plan, and optionally new billing terms
 * (currency, period). Modules the new plan leaves out stop working everywhere
 * in the tree; their settings and data are kept and come back with the plan.
 * Limits already exceeded are allowed: nothing is removed, only new users or
 * branches are refused until usage is below the limit again.
 */
class ChangePlan
{
    public function __construct(
        private PlanCatalog $plans,
        private ModuleResolver $modules,
        private ModuleRegistry $registry,
        private ModuleCache $moduleCache,
        private RuleCache $ruleCache,
        private UsageLimiter $limits,
        private AuditLogger $audit,
        private SubscriptionService $subscriptions,
    ) {}

    /**
     * What the change would do, without changing anything.
     *
     * @return array<string, mixed>
     */
    public function preview(Organization $root, string|PlanChoice $choice): array
    {
        $choice = PlanChoice::of($choice);
        $this->assertChangeable($root, $choice);

        try {
            DB::transaction(function () use ($root, $choice) {
                throw new PreviewResult($this->apply($root, $choice));
            });
        } catch (PreviewResult $result) {
            return $result->summary;
        } finally {
            // Anything cached while the change was only pretended is discarded.
            $root->refresh();
            $this->moduleCache->flushTree($root->getKey());
            $this->ruleCache->flushTree($root->getKey());
        }

        throw new RuntimeException('Plan preview did not finish.');
    }

    /**
     * @param  User|null  $actor  null: the platform itself (e.g. a free trial ending).
     * @return array<string, mixed>
     */
    public function handle(Organization $root, string|PlanChoice $choice, string $reason, ?User $actor): array
    {
        $choice = PlanChoice::of($choice);
        $this->assertChangeable($root, $choice);

        return DB::transaction(function () use ($root, $choice, $reason, $actor) {
            Organization::query()->whereKey($root->getKey())->lockForUpdate()->first();
            $from = $this->planOf($root);
            $subscription = $this->subscriptions->for($root);
            $was = ['partner_plan' => $subscription->partner_plan_id, 'currency' => $subscription->currency_code, 'period' => $subscription->period];

            $summary = $this->apply($root, $choice, dispatch: ['actor' => $actor, 'reason' => $reason]);

            $this->audit->record(
                action: 'organization.plan_changed',
                target: $root,
                old: ['plan' => $from, ...$was],
                new: [
                    'plan' => $choice->planKey,
                    'partner_plan' => $choice->partnerPlan?->getKey(),
                    'currency' => $summary['currency'],
                    'period' => $summary['period'],
                    'modules_off' => $summary['modules_off'],
                    'modules_on' => $summary['modules_on'],
                ],
                reason: $reason,
                actor: $actor,
                organizationId: $root->getKey(),
                partnerId: $root->partner_id,
            );

            return $summary;
        });
    }

    private function assertChangeable(Organization $root, PlanChoice $choice): void
    {
        if (! $root->isRoot()) {
            throw PackagingException::notTopLevel();
        }

        if (! $this->plans->has($choice->planKey)) {
            throw PackagingException::unknownPlan();
        }

        // A personal workspace takes personal plans, an organization business plans.
        $audience = $root->type === OrganizationType::Personal ? PlanCatalog::PERSONAL : PlanCatalog::BUSINESS;
        if ($this->plans->get($choice->planKey)->audience !== $audience) {
            throw PackagingException::wrongAudience($audience);
        }

        $partnerPlan = $choice->partnerPlan;
        // Only the client's own partner's plans, and only while they are offered.
        if ($partnerPlan !== null && ($partnerPlan->partner_id !== $root->partner_id || ! $partnerPlan->isActive())) {
            throw PackagingException::unknownPlan();
        }

        $subscription = $this->subscriptions->for($root);
        $currency = $choice->currency ?? $subscription->currency_code;
        $period = $choice->period ?? $subscription->period;

        if ($this->planOf($root) === $choice->planKey
            && $subscription->partner_plan_id === $partnerPlan?->getKey()
            && $subscription->currency_code === $currency
            && $subscription->period === $period) {
            throw PackagingException::samePlan();
        }

        // We invoice the client ourselves (direct, revenue share): it needs a price to bill.
        if ($root->partner?->billing_mode !== BillingMode::Wholesale) {
            $price = $partnerPlan !== null
                ? $partnerPlan->loadMissing('prices')->price($currency, $period)
                : $this->subscriptions->listPrice($choice->planKey, $currency, $period);

            if ($price === null) {
                throw PackagingException::noPrice($currency, $period);
            }
        }
    }

    /**
     * @param  array{actor: ?User, reason: string}|null  $dispatch  Fire module events (real change only).
     * @return array<string, mixed>
     */
    private function apply(Organization $root, PlanChoice $choice, ?array $dispatch = null): array
    {
        $plan = $choice->planKey;
        /** @var Collection<int, Organization> $tree Top first. */
        $tree = Organization::query()->where('root_id', $root->getKey())->orderBy('depth')->get();
        $before = $tree->mapWithKeys(fn (Organization $node) => [$node->getKey() => $this->modules->fresh($node)]);

        // The plan is the subscription's: the top sets it, every unit below follows.
        foreach ($tree as $node) {
            $node->forceFill(['plan_key' => $node->is($root) ? $plan : null])->save();
        }
        $root->setRawAttributes($tree->first()->getAttributes(), true);

        /** @var Subscription $subscription */
        $subscription = $this->subscriptions->for($root);
        $subscription->forceFill([
            'partner_plan_id' => $choice->partnerPlan?->getKey(),
            'currency_code' => $choice->currency ?? $subscription->currency_code,
            'period' => $choice->period ?? $subscription->period,
        ])->save();

        $after = $tree->mapWithKeys(fn (Organization $node) => [$node->getKey() => $this->modules->fresh($node)]);

        $off = [];
        $on = [];
        $changes = [];

        foreach ($tree as $node) {
            foreach ($after[$node->getKey()] as $key => $now) {
                $was = $before[$node->getKey()][$key]->enabled;
                if ($was === $now->enabled) {
                    continue;
                }

                $changes[$node->getKey()][$key] = $now->enabled;
                $now->enabled ? $on[$key] = true : $off[$key] = true;

                // One event per change, at the highest unit where it happens.
                $parentChanged = ($changes[$node->parent_id][$key] ?? null) === $now->enabled;
                if ($dispatch !== null && ! $parentChanged) {
                    $now->enabled
                        ? ModuleEnabled::dispatch($node, $key, $dispatch['actor'], $dispatch['reason'])
                        : ModuleDisabled::dispatch($node, $key, $dispatch['actor'], $dispatch['reason']);
                }
            }
        }

        $usage = $this->limits->usage($root);
        $limits = $this->limits->limits($root);
        $over = [];
        foreach ($limits as $limit => $max) {
            if ($max !== null && $usage[$limit] !== null && $usage[$limit] > $max) {
                $over[] = ['limit' => $limit, 'max' => $max, 'used' => $usage[$limit]];
            }
        }

        return [
            'plan' => ['key' => $plan, 'name' => $choice->partnerPlan?->label() ?? $this->plans->get($plan)->label()],
            'partner_plan' => $choice->partnerPlan === null ? null : ['id' => $choice->partnerPlan->getKey(), 'name' => $choice->partnerPlan->label()],
            'currency' => $subscription->currency_code,
            'period' => $subscription->period,
            'price_minor' => $this->subscriptions->price($subscription, $root),
            'modules_off' => $this->named(array_keys($off)),
            'modules_on' => $this->named(array_keys($on)),
            'limits' => array_map(fn ($max, $limit) => ['limit' => $limit, 'max' => $max, 'used' => $usage[$limit]], $limits, array_keys($limits)),
            'over_limits' => $over,
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key: string, name: string}>
     */
    private function named(array $keys): array
    {
        sort($keys);

        return array_map(fn (string $key) => ['key' => $key, 'name' => $this->registry->get($key)->label()], $keys);
    }

    private function planOf(Organization $root): ?string
    {
        return $root->plan_key ?? config('tenancy.defaults.plan_key');
    }
}
