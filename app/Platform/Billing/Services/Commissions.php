<?php

namespace App\Platform\Billing\Services;

use App\Platform\Billing\Models\Commission;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Money;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\BillingMode;

/**
 * The partner's share of revenue-share invoices: a percentage of the
 * subtotal (before tax), at the rate that applied when the invoice was
 * issued. A credit note takes back the same share at the same rate.
 */
class Commissions
{
    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function record(Invoice $invoice): ?Commission
    {
        if ($invoice->billing_mode !== BillingMode::RevenueShare->value || $invoice->billed_to !== Invoice::TO_ORGANIZATION) {
            return null;
        }

        $original = $invoice->isCreditNote()
            ? Commission::query()->where('invoice_id', $invoice->credits_invoice_id)->first()
            : null;

        if ($invoice->isCreditNote() && $original === null) {
            return null;
        }

        $rate = $original?->rate_bp ?? (int) $this->rules->get('partners.revenue_share_bp', $this->contexts->forPartner($invoice->partner));
        $amount = Money::share($invoice->subtotal_minor, $rate);

        $commission = new Commission;
        $commission->forceFill([
            'partner_id' => $invoice->partner_id,
            'invoice_id' => $invoice->getKey(),
            'organization_id' => $invoice->organization_id,
            'currency_code' => $invoice->currency_code,
            'rate_bp' => $rate,
            'base_minor' => $invoice->subtotal_minor,
            'amount_minor' => $invoice->isCreditNote() ? -$amount : $amount,
            // Earned once the client pays; a reversal follows its original.
            'status' => $original === null || $original->status === Commission::PENDING ? Commission::PENDING : Commission::PAYABLE,
        ])->save();

        return $commission;
    }

    /**
     * The client paid: the invoice's commission (and any reversals of it) can be paid out.
     */
    public function release(Invoice $invoice): void
    {
        Commission::query()
            ->where('status', Commission::PENDING)
            ->whereIn('invoice_id', Invoice::query()->whereKey($invoice->getKey())->orWhere('credits_invoice_id', $invoice->getKey())->select('id'))
            ->update(['status' => Commission::PAYABLE, 'updated_at' => now()]);
    }
}
