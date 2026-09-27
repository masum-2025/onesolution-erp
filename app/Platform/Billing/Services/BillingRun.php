<?php

namespace App\Platform\Billing\Services;

use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Models\WholesalePrice;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Packaging\Services\UsageLimiter;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\PartnerStatus;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The monthly billing run, in advance, for one calendar month (UTC):
 *
 * - Wholesale partners: one invoice to the partner, in its billing currency,
 *   with a line per active client (per client, or per staff seat) at our
 *   wholesale price.
 * - Clients of direct and revenue-share partners: an invoice per client
 *   whose subscription period starts this month (monthly, or yearly on its
 *   anniversary month), at its partner plan's or our list price, carrying the
 *   partner's brand. Revenue share adds the partner's commission.
 *
 * Self-serve subscriptions (personal workspaces) are left out: the client
 * buys them at checkout and they renew on their own dates (Phase 5C-2).
 *
 * No proration: a subscription is billed from the first month that starts on
 * or after the day it began. Safe to repeat: every document has a billing
 * key, so a month is never billed twice.
 */
class BillingRun
{
    public function __construct(
        private InvoiceIssuer $issuer,
        private Commissions $commissions,
        private WholesalePriceBook $prices,
        private SubscriptionService $subscriptions,
        private UsageLimiter $usage,
        private LineTexts $texts,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function run(CarbonImmutable $month, ?Partner $only = null): BillingRunResult
    {
        $month = $month->utc()->startOfMonth();
        $result = new BillingRunResult;

        $partners = Partner::query()
            ->where('status', PartnerStatus::Active)
            ->when($only, fn ($query) => $query->whereKey($only->getKey()))
            ->orderBy('name')
            ->get();

        foreach ($partners as $partner) {
            $partner->billing_mode === BillingMode::Wholesale
                ? $this->wholesale($partner, $month, $result)
                : $this->clients($partner, $month, $result);
        }

        return $result;
    }

    private function wholesale(Partner $partner, CarbonImmutable $month, BillingRunResult $result): void
    {
        $key = "wholesale:{$partner->getKey()}:{$month->format('Y-m')}";
        if (Invoice::query()->where('billing_key', $key)->exists()) {
            $result->already++;

            return;
        }

        $context = $this->contexts->forPartner($partner);
        $currency = (string) $this->rules->get('billing.partner_currency', $context);
        $lines = [];

        foreach ($this->activeClients($partner) as $root) {
            $subscription = $this->subscriptions->for($root);
            if ($subscription->started_on->greaterThan($month)) {
                continue;
            }

            $planKey = $this->subscriptions->planKey($root);
            $price = $this->prices->find($partner, $planKey, $currency, $month);
            if ($price === null) {
                $result->skip($partner->name, __('billing.run.no_wholesale_price', ['client' => $root->displayName(), 'plan' => $planKey, 'currency' => $currency]));

                continue;
            }

            $perSeat = $price->unit === WholesalePrice::PER_SEAT;
            $quantity = $perSeat ? (int) $this->usage->usage($root)['users'] : 1;
            if ($quantity === 0) {
                continue;
            }

            $lines[] = [
                'organization_id' => $root->getKey(),
                'plan_key' => $planKey,
                'description' => $this->texts->make($perSeat ? 'wholesale_seats' : 'wholesale_client', fn (string $locale) => [
                    'client' => $this->texts->name($root->texts('name'), $locale),
                    'plan' => $this->texts->planName($planKey, null, $locale),
                    'month' => $this->texts->month($month, $locale),
                ]),
                'quantity' => $quantity,
                'unit_amount_minor' => $price->amount_minor,
            ];
        }

        if ($lines === []) {
            return;
        }

        $this->issue(new InvoiceDraft(
            type: Invoice::INVOICE,
            billedTo: Invoice::TO_PARTNER,
            partner: $partner,
            organization: null,
            brandPartner: $this->house() ?? $partner,
            billingMode: BillingMode::Wholesale->value,
            currency: $currency,
            taxRateBp: (int) $this->rules->get('billing.tax_rate_bp', $context),
            paymentTermsDays: (int) $this->rules->get('billing.payment_terms_days', $context),
            lines: $lines,
            billingKey: $key,
            periodStart: $month,
            periodEnd: $month->endOfMonth()->startOfDay(),
        ), $result);
    }

    private function clients(Partner $partner, CarbonImmutable $month, BillingRunResult $result): void
    {
        foreach ($this->activeClients($partner) as $root) {
            $subscription = $this->subscriptions->for($root);

            // Self-serve plans renew on their own dates (SelfServe\Renewals).
            if ($subscription->self_serve) {
                continue;
            }

            $key = "client:{$root->getKey()}:{$month->toDateString()}";

            if ($subscription->billed_through !== null && ! $subscription->billed_through->lessThan($month)) {
                // Covered already: this month's invoice, or a yearly one from an earlier month.
                Invoice::query()->where('billing_key', $key)->exists() && $result->already++;

                continue;
            }

            if ($subscription->status !== Subscription::ACTIVE || $subscription->started_on->greaterThan($month)) {
                continue;
            }

            $end = ($subscription->period === 'yearly' ? $month->addYear() : $month->addMonth())->subDay();

            DB::transaction(function () use ($partner, $root, $subscription, $month, $end, $key, $result) {
                $subscription = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->first();
                if ($subscription->billed_through !== null && ! $subscription->billed_through->lessThan($month)) {
                    return;
                }

                $price = $this->subscriptions->price($subscription, $root);
                if ($price === null) {
                    $result->skip($partner->name, __('billing.run.no_client_price', [
                        'client' => $root->displayName(),
                        'currency' => $subscription->currency_code,
                        'period' => __("packaging.periods.{$subscription->period}"),
                    ]));

                    return;
                }

                // A free plan is not invoiced; the period still counts as billed.
                if ($price > 0) {
                    $context = $this->contexts->forOrganization($root);
                    $planKey = $this->subscriptions->planKey($root);
                    $partnerPlan = $subscription->partnerPlan;

                    $invoice = $this->issue(new InvoiceDraft(
                        type: Invoice::INVOICE,
                        billedTo: Invoice::TO_ORGANIZATION,
                        partner: $partner,
                        organization: $root,
                        brandPartner: $partner,
                        billingMode: $partner->billing_mode->value,
                        currency: $subscription->currency_code,
                        taxRateBp: (int) $this->rules->get('billing.tax_rate_bp', $context),
                        paymentTermsDays: (int) $this->rules->get('billing.payment_terms_days', $context),
                        lines: [[
                            'organization_id' => $root->getKey(),
                            'plan_key' => $planKey,
                            'description' => $this->texts->make("subscription_{$subscription->period}", fn (string $locale) => [
                                'plan' => $this->texts->planName($planKey, $partnerPlan, $locale),
                                'from' => $this->texts->day($month, $locale),
                                'to' => $this->texts->day($end, $locale),
                            ]),
                            'quantity' => 1,
                            'unit_amount_minor' => $price,
                        ]],
                        billingKey: $key,
                        periodStart: $month,
                        periodEnd: $end,
                    ), $result);

                    if ($invoice === null) {
                        return;
                    }

                    $this->commissions->record($invoice);
                }

                $subscription->forceFill(['billed_through' => $end->toDateString()])->save();
            });
        }
    }

    private function issue(InvoiceDraft $draft, BillingRunResult $result): ?Invoice
    {
        try {
            $invoice = $this->issuer->issue($draft);
        } catch (UniqueConstraintViolationException) {
            // Another run issued it at the same moment.
            $result->already++;

            return null;
        }

        $result->issued[] = $invoice->number;

        return $invoice;
    }

    /**
     * @return iterable<Organization>
     */
    private function activeClients(Partner $partner): iterable
    {
        return Organization::query()
            ->where('partner_id', $partner->getKey())
            ->whereNull('parent_id')
            ->where('status', OrganizationStatus::Active)
            ->lazyById();
    }

    private function house(): ?Partner
    {
        return Partner::query()->where('is_house', true)->first();
    }
}
