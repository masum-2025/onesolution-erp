<?php

namespace App\Platform\Addons;

use App\Models\User;
use App\Platform\Addons\Events\AddonEnded;
use App\Platform\Addons\Events\AddonStarted;
use App\Platform\Addons\Exceptions\AddonException;
use App\Platform\Addons\Models\OrganizationAddon;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Services\Commissions;
use App\Platform\Billing\Services\InvoiceDraft;
use App\Platform\Billing\Services\InvoiceIssuer;
use App\Platform\Billing\Services\LineTexts;
use App\Platform\Modules\Enums\ModuleState;
use App\Platform\Modules\Exceptions\ModuleException;
use App\Platform\Modules\ModuleDefinition;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Modules\Services\ModuleToggleService;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Starting, cancelling and renewing add-ons: modules an account (its top
 * organization) buys beyond its plan. The code of every module is deployed
 * by the platform; buying one only gives the account the right to it and
 * turns it on at the top organization (its tables already exist).
 *
 * - Trial: free for billing.addon_trial_days, once per module and account.
 * - Bought: on at once, month by month from that day. A client of a direct
 *   or revenue-share partner gets an invoice for each month (due after its
 *   payment terms, paid like any other invoice); a wholesale partner pays
 *   for it on its monthly invoice and bills its client itself.
 * - Cancelled: runs to the end of the paid month, then the module turns
 *   off. Its data stays (deleting it is the separate module purge).
 *
 * Prices and dates come from the server; a person's click carries an op_id,
 * so a double click never starts or bills twice.
 */
class Addons
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleResolver $resolver,
        private ModuleToggleService $toggles,
        private AddonPriceBook $prices,
        private SubscriptionService $subscriptions,
        private InvoiceIssuer $issuer,
        private Commissions $commissions,
        private LineTexts $texts,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    public function rule(string $key, Organization $root): mixed
    {
        return $this->rules->get($key, $this->contexts->forOrganization($root));
    }

    /** Whether we invoice the client (false: its wholesale partner does). */
    public function billsClient(Organization $root): bool
    {
        return $root->partner->billing_mode !== BillingMode::Wholesale;
    }

    public function currency(Organization $root): string
    {
        return $this->subscriptions->for($root)->currency_code;
    }

    /** What the client pays a month (null: no price in its currency, or billed by its partner). */
    public function clientPrice(Organization $root, string $module): ?int
    {
        return $this->prices->listPrice($root->partner, $module, $this->currency($root));
    }

    /** A module the account may buy: sellable, offered, for its sector, not in its plan, and priced. */
    public function assertForSale(Organization $root, string $module): ModuleDefinition
    {
        if (! $this->registry->has($module)) {
            throw AddonException::unknown();
        }

        $definition = $this->registry->get($module);
        $resolved = $this->resolver->fresh($root)[$module];
        $label = $definition->label();

        if (! $definition->sellable || in_array($resolved->reason->value, ['not_offered', 'sector_not_allowed'], true)) {
            throw AddonException::notForSale($label);
        }

        if ($resolved->available && ! $resolved->viaAddon) {
            throw AddonException::inPlan($label);
        }

        if ($resolved->lockedByPartner && $resolved->state === ModuleState::Disabled) {
            throw AddonException::moduleOff($label);
        }

        $this->assertPriced($root, $module, $label);

        return $definition;
    }

    public function trial(Organization $root, string $module, User $actor, string $opId): OrganizationAddon
    {
        return $this->once($root, $module, $opId, function () use ($root, $module, $actor, $opId) {
            $definition = $this->assertForSale($root, $module);
            $days = (int) $this->rule('billing.addon_trial_days', $root);
            if ($days < 1) {
                throw AddonException::noTrial();
            }

            $addon = $this->row($root, $module);
            if ($addon->exists && $addon->isRunning()) {
                throw AddonException::alreadyRunning($definition->label());
            }
            if ($addon->trial_used) {
                throw AddonException::trialUsed($definition->label());
            }
            $this->assertRequirements($root, $definition);

            $addon->forceFill([
                'status' => OrganizationAddon::TRIAL,
                'trial_ends_at' => CarbonImmutable::now()->addDays($days),
                'trial_used' => true,
                'cancel_at_period_end' => false,
                'ended_at' => null,
                'bought_by' => $actor->getKey(),
                'op_id' => $opId,
            ]);

            return $this->start($root, $addon, $actor, 'addons.trial_started', ['trial_ends_at' => $addon->trial_ends_at->toIso8601String()]);
        });
    }

    /** Buys an add-on (also ends a trial of it): on at once, the first month billed now. */
    public function buy(Organization $root, string $module, User $actor, string $opId): OrganizationAddon
    {
        return $this->once($root, $module, $opId, function () use ($root, $module, $actor, $opId) {
            $definition = $this->assertForSale($root, $module);
            $addon = $this->row($root, $module);
            if ($addon->exists && $addon->status === OrganizationAddon::ACTIVE) {
                throw AddonException::alreadyRunning($definition->label());
            }
            $this->assertRequirements($root, $definition);

            $today = CarbonImmutable::today();
            $addon->forceFill([
                'status' => OrganizationAddon::ACTIVE,
                'trial_ends_at' => null,
                'period_start' => $today->toDateString(),
                'period_end' => $this->periodEnd($today)->toDateString(),
                'cancel_at_period_end' => false,
                'ended_at' => null,
                'bought_by' => $actor->getKey(),
                'op_id' => $opId,
            ]);

            return DB::transaction(function () use ($root, $addon, $actor, $today) {
                $invoice = $this->billMonth($root, $addon, $today, $actor);

                return $this->start($root, $addon, $actor, 'addons.bought', [
                    'amount_minor' => $addon->amount_minor,
                    'currency' => $addon->currency_code,
                    'invoice' => $invoice?->number,
                ]);
            });
        });
    }

    /** A trial ends now; a bought add-on runs to the end of its paid month. */
    public function cancel(Organization $root, string $module, User $actor): OrganizationAddon
    {
        $addon = $this->running($root, $module);

        if ($addon->status === OrganizationAddon::TRIAL) {
            return $this->end($addon, 'cancelled', $actor);
        }

        $addon->forceFill(['cancel_at_period_end' => true])->save();
        $this->audit->record('addons.cancelled', $addon, new: ['module' => $module, 'runs_until' => $addon->period_end->toDateString()], actor: $actor, organizationId: $root->getKey(), partnerId: $root->partner_id);

        return $addon;
    }

    /** Takes back a cancellation before the month ends. */
    public function resume(Organization $root, string $module, User $actor): OrganizationAddon
    {
        $addon = $this->running($root, $module);
        if ($addon->status === OrganizationAddon::ACTIVE && $addon->cancel_at_period_end) {
            $this->assertPriced($root, $module, $this->registry->get($module)->label());
            $addon->forceFill(['cancel_at_period_end' => false])->save();
            $this->audit->record('addons.resumed', $addon, new: ['module' => $module], actor: $actor, organizationId: $root->getKey(), partnerId: $root->partner_id);
        }

        return $addon;
    }

    /**
     * The daily cycle: trials that ran out and cancelled months end; paid
     * months that ran out renew (a new invoice) or end when the module has
     * no price any more. Safe to repeat: every month has its own billing key.
     *
     * @return array{renewed: int, ended: int}
     */
    public function cycle(CarbonImmutable $now): array
    {
        $result = ['renewed' => 0, 'ended' => 0];
        $today = $now->utc()->startOfDay();

        $due = OrganizationAddon::query()
            ->where(fn ($query) => $query
                ->where(fn ($trial) => $trial->where('status', OrganizationAddon::TRIAL)->where('trial_ends_at', '<=', $now))
                ->orWhere(fn ($paid) => $paid->where('status', OrganizationAddon::ACTIVE)->where('period_end', '<', $today->toDateString())))
            ->with('organization.partner')
            ->lazyById();

        foreach ($due as $addon) {
            $root = $addon->organization;
            if (! $this->registry->has($addon->module_key)) {
                continue;
            }

            if ($addon->status === OrganizationAddon::TRIAL) {
                $this->end($addon, 'trial_over');
                $result['ended']++;

                continue;
            }

            if ($addon->cancel_at_period_end) {
                $this->end($addon, 'cancelled');
                $result['ended']++;

                continue;
            }

            // A suspended account is not billed; its add-on waits for it.
            if ($root->status !== OrganizationStatus::Active) {
                continue;
            }

            $renewed = $this->renew($root, $addon);
            $result[$renewed ? 'renewed' : 'ended']++;
        }

        return $result;
    }

    public function end(OrganizationAddon $addon, string $why, ?User $actor = null): OrganizationAddon
    {
        $root = $addon->organization ?? Organization::query()->findOrFail($addon->organization_id);

        $this->toggles->withTransitions($root, $actor, "Add-on ended ({$why}).", function () use ($addon) {
            $addon->forceFill([
                'status' => OrganizationAddon::ENDED,
                'ended_at' => CarbonImmutable::now(),
                'cancel_at_period_end' => false,
                'trial_ends_at' => null,
            ])->save();
        });

        $this->audit->record('addons.ended', $addon, new: ['module' => $addon->module_key, 'why' => $why], actor: $actor, organizationId: $root->getKey(), partnerId: $root->partner_id);
        AddonEnded::dispatch($addon, $why);

        return $addon;
    }

    public function periodEnd(CarbonImmutable $start): CarbonImmutable
    {
        return $start->addMonthNoOverflow()->subDay();
    }

    private function renew(Organization $root, OrganizationAddon $addon): bool
    {
        if (! $this->billsClient($root)) {
            // The wholesale partner pays on its monthly invoice: the month simply moves on.
            $this->movePeriod($addon, $addon->period_end->addDay());

            return true;
        }

        if ($this->clientPrice($root, $addon->module_key) === null) {
            $this->end($addon, 'no_price');

            return false;
        }

        DB::transaction(function () use ($root, $addon) {
            $start = $addon->period_end->addDay();
            $this->movePeriod($addon, $start);
            $this->billMonth($root, $addon, $start, null);
        });

        return true;
    }

    private function movePeriod(OrganizationAddon $addon, CarbonImmutable $start): void
    {
        $addon->forceFill(['period_start' => $start->toDateString(), 'period_end' => $this->periodEnd($start)->toDateString()])->save();
    }

    /**
     * The invoice for one month of an add-on, at today's price (none for a
     * wholesale partner's client). Due after the account's payment terms.
     */
    private function billMonth(Organization $root, OrganizationAddon $addon, CarbonImmutable $start, ?User $actor): ?Invoice
    {
        if (! $this->billsClient($root)) {
            $addon->forceFill(['currency_code' => null, 'amount_minor' => null]);

            return null;
        }

        $currency = $this->currency($root);
        $price = (int) $this->clientPrice($root, $addon->module_key);
        $addon->forceFill(['currency_code' => $currency, 'amount_minor' => $price]);
        if ($price < 1) {
            return null;
        }

        $end = $this->periodEnd($start);
        $module = $this->registry->get($addon->module_key);

        try {
            $invoice = $this->issuer->issue(new InvoiceDraft(
                type: Invoice::INVOICE,
                billedTo: Invoice::TO_ORGANIZATION,
                partner: $root->partner,
                organization: $root,
                brandPartner: $root->partner,
                billingMode: $root->partner->billing_mode->value,
                currency: $currency,
                taxRateBp: (int) $this->rule('billing.tax_rate_bp', $root),
                paymentTermsDays: (int) $this->rule('billing.payment_terms_days', $root),
                lines: [[
                    'organization_id' => $root->getKey(),
                    'plan_key' => null,
                    'description' => $this->texts->make('addon_monthly', fn (string $locale) => [
                        'module' => $module->label($locale),
                        'from' => $this->texts->day($start, $locale),
                        'to' => $this->texts->day($end, $locale),
                    ]),
                    'quantity' => 1,
                    'unit_amount_minor' => $price,
                ]],
                billingKey: "addon:{$root->getKey()}:{$addon->module_key}:{$start->toDateString()}",
                periodStart: $start,
                periodEnd: $end,
            ), $actor);
        } catch (UniqueConstraintViolationException) {
            // That month was billed already (a repeated run).
            return null;
        }

        $this->commissions->record($invoice);

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function start(Organization $root, OrganizationAddon $addon, User $actor, string $action, array $details): OrganizationAddon
    {
        $this->toggles->withTransitions($root, $actor, 'Add-on started.', fn () => $addon->save());

        // Turn it on where it was bought (its required modules too, when the account has them).
        if (! $this->resolver->fresh($root)[$addon->module_key]->enabled) {
            try {
                $this->toggles->enable($root, $addon->module_key, 'Add-on started.', actor: $actor);
            } catch (ModuleException) {
                // Locked off above the account: the add-on is there; whoever locked it decides.
            }
        }

        $this->audit->record($action, $addon, new: ['module' => $addon->module_key, ...$details], actor: $actor, organizationId: $root->getKey(), partnerId: $root->partner_id);
        AddonStarted::dispatch($addon, $actor);

        return $addon;
    }

    /**
     * One click, one result: the same op_id gives back what it did the first time.
     *
     * @param  callable(): OrganizationAddon  $work
     */
    private function once(Organization $root, string $module, string $opId, callable $work): OrganizationAddon
    {
        $done = OrganizationAddon::query()->where('organization_id', $root->getKey())->where('op_id', $opId)->first();
        if ($done !== null) {
            if ($done->module_key !== $module) {
                throw AddonException::opReused();
            }

            return $done;
        }

        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            // The same click arrived twice at once: the first one carries on.
            return OrganizationAddon::query()->where('organization_id', $root->getKey())->where('module_key', $module)->firstOrFail();
        }
    }

    private function row(Organization $root, string $module): OrganizationAddon
    {
        $addon = OrganizationAddon::query()->where('organization_id', $root->getKey())->where('module_key', $module)->first();
        if ($addon !== null) {
            return $addon;
        }

        $addon = new OrganizationAddon;
        $addon->forceFill(['organization_id' => $root->getKey(), 'partner_id' => $root->partner_id, 'module_key' => $module]);

        return $addon;
    }

    private function running(Organization $root, string $module): OrganizationAddon
    {
        $addon = OrganizationAddon::query()->where('organization_id', $root->getKey())->where('module_key', $module)->first();
        if ($addon === null || ! $addon->isRunning()) {
            throw AddonException::notRunning($this->registry->has($module) ? $this->registry->get($module)->label() : $module);
        }

        return $addon->setRelation('organization', $root);
    }

    private function assertPriced(Organization $root, string $module, string $label): void
    {
        if ($this->billsClient($root)) {
            $currency = $this->currency($root);
            if ($this->clientPrice($root, $module) === null) {
                throw AddonException::noPrice($label, $currency);
            }

            return;
        }

        $currency = (string) $this->rules->get('billing.partner_currency', $this->contexts->forPartner($root->partner));
        if ($this->prices->wholesalePrice($root->partner, $module, $currency) === null) {
            throw AddonException::noPrice($label, $currency);
        }
    }

    /** Every required module must be on, in the plan, or bought already. */
    private function assertRequirements(Organization $root, ModuleDefinition $definition): void
    {
        $resolved = $this->resolver->fresh($root);
        $missing = array_values(array_filter($definition->requires, fn (string $required) => ! $resolved[$required]->available));

        if ($missing !== []) {
            throw AddonException::needsFirst(
                $definition->label(),
                implode(', ', array_map(fn (string $key) => $this->registry->get($key)->label(), $missing)),
                $missing,
            );
        }
    }
}
