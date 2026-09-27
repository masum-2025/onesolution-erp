<?php

namespace App\Platform\Billing\SelfServe;

use App\Platform\Billing\Money;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Support\LocalDate;
use App\Platform\Tenancy\Models\Organization;

/**
 * Prices a paid personal plan for a new period starting today, and says
 * plainly why not when it cannot be bought now:
 *
 * - an unpaid invoice comes first (pay it, or move to the free plan);
 * - during a paid period the plan changes only when it ends (changing in the
 *   middle of a period, with proration, comes in a later phase);
 * - free plans are not bought: moving to one is its own action.
 */
class Quotes
{
    public function __construct(private SelfServeAccount $account) {}

    public function quote(Organization $root, string $planKey, string $period): Quote
    {
        $subscription = $this->account->subscription($root);

        if (! $this->account->isOffered($planKey, $root) || ! in_array($period, PlanCatalog::PERIODS, true)) {
            throw PaymentException::planNotOffered();
        }

        $price = $this->account->price($planKey, $subscription, $period);
        if ($price === null || $price < 1) {
            throw PaymentException::noPrice();
        }

        $this->assertCanBuy($root, $subscription, $planKey, $period);

        $taxRate = (int) $this->account->rule('billing.tax_rate_bp', $root);
        $start = $this->account->today();

        return new Quote(
            planKey: $planKey,
            period: $period,
            currency: $subscription->currency_code,
            subtotalMinor: $price,
            taxRateBp: $taxRate,
            taxMinor: Money::share($price, $taxRate),
            start: $start,
            end: $this->account->periodEnd($start, $period),
        );
    }

    private function assertCanBuy(Organization $root, Subscription $subscription, string $planKey, string $period): void
    {
        $open = $this->account->openInvoices($root)->first();
        if ($open !== null) {
            throw PaymentException::payOpenInvoice($open->number);
        }

        $paidUntil = $subscription->billed_through;
        if ($paidUntil !== null && ! $paidUntil->lessThan($this->account->today()) && $this->account->isPaid($root, $subscription)) {
            $until = LocalDate::format($paidUntil);

            throw $this->account->planKey($root) === $planKey && $subscription->period === $period
                ? PaymentException::alreadyOnPlan($until)
                : PaymentException::changeAtPeriodEnd($until);
        }
    }
}
