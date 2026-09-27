<?php

namespace App\Platform\Billing\SelfServe;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Services\Commissions;
use App\Platform\Billing\Services\InvoiceDraft;
use App\Platform\Billing\Services\InvoiceIssuer;
use App\Platform\Billing\Services\InvoicePayments;
use App\Platform\Billing\Services\LineTexts;
use App\Platform\Packaging\Actions\ChangePlan;
use App\Platform\Packaging\Actions\PlanChoice;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Payments\Contracts\PaymentFulfiller;
use App\Platform\Payments\Models\Payment;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * What a confirmed payment gives, inside the confirmation's transaction:
 *
 * - checkout: an invoice for the new period (the exact amounts paid), marked
 *   paid at once, the plan switched if it changed, the period recorded, and
 *   any trial ended (bought now);
 * - invoice: that invoice marked paid. Already paid another way: the payment
 *   is flagged for a refund outside the system, never applied twice.
 *
 * Either way, an account read-only for an overdue bill works again as soon
 * as nothing is overdue.
 */
class Fulfilment implements PaymentFulfiller
{
    public function __construct(
        private SelfServeAccount $account,
        private AccountStanding $standing,
        private InvoiceIssuer $issuer,
        private InvoicePayments $payments,
        private Commissions $commissions,
        private LineTexts $texts,
        private ChangePlan $changePlan,
        private AuditLogger $audit,
    ) {}

    public function fulfil(Payment $payment): void
    {
        $root = Organization::query()->with('partner')->findOrFail($payment->organization_id);
        $subscription = Subscription::query()->where('organization_id', $root->getKey())->lockForUpdate()->firstOrFail();
        $actor = $payment->user_id === null ? null : User::query()->find($payment->user_id);

        $payment->purpose === Payment::CHECKOUT
            ? $this->plan($payment, $root, $subscription, $actor)
            : $this->invoice($payment, $root, $actor);

        $this->standing->refresh($root, $subscription->fresh(), $actor);
    }

    private function plan(Payment $payment, Organization $root, Subscription $subscription, ?User $actor): void
    {
        $today = $this->account->today();
        $samePlan = $this->account->planKey($root) === $payment->plan_key && $subscription->period === $payment->period;
        $paidUntil = $subscription->billed_through;

        // Paid twice for the same plan (two tabs): the second period follows the first.
        $start = $samePlan && ! $subscription->onTrial() && $paidUntil !== null && ! $paidUntil->lessThan($today)
            ? $paidUntil->addDay()
            : $today;
        $end = $this->account->periodEnd($start, $payment->period);

        $invoice = $this->issuer->issue(new InvoiceDraft(
            type: Invoice::INVOICE,
            billedTo: Invoice::TO_ORGANIZATION,
            partner: $root->partner,
            organization: $root,
            brandPartner: $root->partner,
            billingMode: $root->partner->billing_mode->value,
            currency: $payment->currency_code,
            taxRateBp: $payment->tax_rate_bp,
            paymentTermsDays: 0,
            lines: [[
                'organization_id' => $root->getKey(),
                'plan_key' => $payment->plan_key,
                'description' => $this->texts->make("subscription_{$payment->period}", fn (string $locale) => [
                    'plan' => $this->texts->planName($payment->plan_key, null, $locale),
                    'from' => $this->texts->day($start, $locale),
                    'to' => $this->texts->day($end, $locale),
                ]),
                'quantity' => 1,
                'unit_amount_minor' => $payment->subtotal_minor,
            ]],
            billingKey: "payment:{$payment->getKey()}",
            periodStart: $start,
            periodEnd: $end,
        ), $actor);

        // Commission first: paying the invoice releases it.
        $this->commissions->record($invoice);
        $this->payments->markPaid($invoice, $this->reference($payment), $actor);

        if (! $samePlan) {
            $this->changePlan->handle(
                $root,
                new PlanChoice($payment->plan_key, period: $payment->period),
                'Bought online (self-serve checkout).',
                $actor,
            );
        }

        $subscription->refresh()->forceFill([
            'self_serve' => true,
            'billed_through' => $end->toDateString(),
            'trial_ends_at' => null,
            'trial_plan_key' => null,
            'trial_reminded' => false,
            'cancel_at_period_end' => false,
        ])->save();

        $payment->forceFill(['invoice_id' => $invoice->getKey()])->save();
    }

    private function invoice(Payment $payment, Organization $root, ?User $actor): void
    {
        $invoice = Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->first();

        if ($invoice !== null && $invoice->type === Invoice::INVOICE && $invoice->status === Invoice::ISSUED) {
            $this->payments->markPaid($invoice, $this->reference($payment), $actor);

            return;
        }

        // Paid already (another tab, or moved to the free plan meanwhile): refund, never apply twice.
        $payment->forceFill(['refund_due' => true])->save();

        $this->audit->record(
            action: 'payments.refund_due',
            target: $payment,
            new: [
                'invoice' => $invoice?->number,
                'invoice_status' => $invoice?->status,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency_code,
            ],
            organizationId: $root->getKey(),
            partnerId: $root->partner_id,
        );
    }

    private function reference(Payment $payment): string
    {
        return mb_substr($payment->gateway.':'.($payment->gateway_txn ?? $payment->getKey()), 0, 100);
    }
}
