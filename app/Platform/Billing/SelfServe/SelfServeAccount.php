<?php

namespace App\Platform\Billing\SelfServe;

use App\Models\User;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Rules\RuleContext;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What every self-serve step needs to know about an account: whether it
 * buys its own plan, its plans and prices, open invoices, and the rules
 * that apply to it (resolved through its partner, plan and country).
 */
class SelfServeAccount
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private PlanCatalog $plans,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * The subscription of a self-serve account we bill ourselves; refuses
     * anything else with a message saying who to ask.
     */
    public function subscription(Organization $root): Subscription
    {
        if (! $root->isRoot()) {
            throw PaymentException::notSelfServe();
        }

        // A wholesale partner bills its own clients at its own price, outside this system.
        if ($root->partner->billing_mode === BillingMode::Wholesale) {
            throw PaymentException::billedByProvider($root->partner->name);
        }

        // Personal workspaces, and companies that grew out of one (Phase 5C-3).
        $subscription = $this->subscriptions->for($root);
        if (! $subscription->self_serve) {
            throw PaymentException::notSelfServe();
        }

        return $subscription;
    }

    /** Personal plans for a personal workspace, business plans for a company. */
    public function audience(Organization $root): string
    {
        return $root->type === OrganizationType::Personal ? PlanCatalog::PERSONAL : PlanCatalog::BUSINESS;
    }

    /** Paying or starting a trial needs a confirmed email or phone. */
    public function assertVerified(User $user): void
    {
        if ($user->email_verified_at === null && $user->phone_verified_at === null) {
            throw PaymentException::unverified();
        }
    }

    public function planKey(Organization $root): string
    {
        return $this->subscriptions->planKey($root);
    }

    /**
     * The plan a personal workspace falls back to (rule b2c.default_plan).
     * null for a company: there is no free business plan.
     */
    public function freePlan(Organization $root): ?string
    {
        return $root->type === OrganizationType::Personal ? (string) $this->rule('b2c.default_plan', $root) : null;
    }

    /** List price of a plan in the subscription's currency; null = not sold that way. */
    public function price(string $planKey, Subscription $subscription, ?string $period = null): ?int
    {
        return $this->subscriptions->listPrice($planKey, $subscription->currency_code, $period ?? $subscription->period);
    }

    public function isPaid(Organization $root, Subscription $subscription): bool
    {
        return ($this->price($this->planKey($root), $subscription) ?? 0) > 0;
    }

    /**
     * Public plans of the account's audience with their prices in its currency.
     *
     * @return list<array{key: string, name: string, description: string, prices: array<string, int>}>
     */
    public function offeredPlans(Organization $root, Subscription $subscription): array
    {
        return $this->plansFor($this->audience($root), $subscription);
    }

    /**
     * @return list<array{key: string, name: string, description: string, prices: array<string, int>}>
     */
    public function plansFor(string $audience, Subscription $subscription): array
    {
        $offered = [];
        foreach ($this->plans->all() as $plan) {
            if ($plan->audience !== $audience || ! $plan->public) {
                continue;
            }

            $prices = [];
            foreach (PlanCatalog::PERIODS as $period) {
                $price = $this->price($plan->key, $subscription, $period);
                if ($price !== null) {
                    $prices[$period] = $price;
                }
            }

            if ($prices !== []) {
                $offered[] = ['key' => $plan->key, 'name' => $plan->label(), 'description' => $plan->description(), 'prices' => $prices];
            }
        }

        return $offered;
    }

    public function isOffered(string $planKey, Organization $root): bool
    {
        return $this->plans->has($planKey)
            && $this->plans->get($planKey)->audience === $this->audience($root)
            && $this->plans->get($planKey)->public;
    }

    /**
     * Unpaid invoices to the account, oldest due first.
     *
     * @return Collection<int, Invoice>
     */
    public function openInvoices(Organization $root): Collection
    {
        return Invoice::query()
            ->where('organization_id', $root->getKey())
            ->where('billed_to', Invoice::TO_ORGANIZATION)
            ->where('type', Invoice::INVOICE)
            ->where('status', Invoice::ISSUED)
            ->orderBy('due_at')
            ->orderBy('number')
            ->get();
    }

    public function overdueInvoice(Organization $root, CarbonImmutable $now): ?Invoice
    {
        return $this->openInvoices($root)->first(fn (Invoice $invoice) => $invoice->due_at !== null && $invoice->due_at->lessThan($now));
    }

    /** The last day of a period that starts on $start: 5 Oct monthly -> 4 Nov. */
    public function periodEnd(CarbonImmutable $start, string $period): CarbonImmutable
    {
        return ($period === 'yearly' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow())->subDay();
    }

    public function rule(string $key, Organization $root): mixed
    {
        return $this->rules->get($key, $this->context($root));
    }

    public function context(Organization $root): RuleContext
    {
        return $this->contexts->forOrganization($root);
    }

    /** Today (UTC, like every stored date): periods are whole days. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfDay();
    }
}
