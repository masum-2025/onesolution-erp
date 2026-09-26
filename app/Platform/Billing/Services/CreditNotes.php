<?php

namespace App\Platform\Billing\Services;

use App\Models\User;
use App\Platform\Billing\Exceptions\BillingException;
use App\Platform\Billing\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Corrects an issued invoice without touching it: a credit note for part or
 * all of its amount before tax (tax at the invoice's own rate). Credit notes
 * together never exceed the invoice. A fully credited unpaid invoice is closed;
 * a paid one is refunded outside the system. Revenue share: the partner's
 * commission is taken back in the same proportion.
 */
class CreditNotes
{
    public function __construct(private InvoiceIssuer $issuer, private Commissions $commissions, private LineTexts $texts) {}

    public function issue(Invoice $invoice, int $amountMinor, string $reason, ?User $actor = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $amountMinor, $reason, $actor) {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($invoice->type !== Invoice::INVOICE || $invoice->status === Invoice::CREDITED) {
                throw BillingException::notCreditable();
            }

            $remaining = $this->remaining($invoice);
            if ($amountMinor < 1 || $amountMinor > $remaining) {
                throw BillingException::creditTooLarge($remaining, $invoice->currency_code);
            }

            $note = $this->issuer->issue(new InvoiceDraft(
                type: Invoice::CREDIT_NOTE,
                billedTo: $invoice->billed_to,
                partner: $invoice->partner,
                organization: $invoice->organization,
                brandPartner: $invoice->brandPartner,
                billingMode: $invoice->billing_mode,
                currency: $invoice->currency_code,
                taxRateBp: $invoice->tax_rate_bp,
                paymentTermsDays: 0,
                lines: [[
                    'organization_id' => $invoice->organization_id,
                    'plan_key' => null,
                    'description' => $this->texts->make('credit', fn () => ['number' => $invoice->number]),
                    'quantity' => 1,
                    'unit_amount_minor' => $amountMinor,
                ]],
                periodStart: $invoice->period_start,
                periodEnd: $invoice->period_end,
                credits: $invoice,
                reason: $reason,
            ), $actor);

            $this->commissions->record($note);

            if ($amountMinor === $remaining && $invoice->status === Invoice::ISSUED) {
                $invoice->forceFill(['status' => Invoice::CREDITED])->save();
            }

            return $note;
        });
    }

    /** What can still be credited, before tax. */
    public function remaining(Invoice $invoice): int
    {
        return $invoice->subtotal_minor - (int) Invoice::query()->where('credits_invoice_id', $invoice->getKey())->sum('subtotal_minor');
    }
}
