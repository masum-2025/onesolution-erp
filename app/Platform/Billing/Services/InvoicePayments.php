<?php

namespace App\Platform\Billing\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\Exceptions\BillingException;
use App\Platform\Billing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records that an invoice was paid (by bank transfer or any other way; no
 * payment gateway yet). Paying a revenue-share invoice makes the partner's
 * commission payable.
 */
class InvoicePayments
{
    public function __construct(private Commissions $commissions, private AuditLogger $audit) {}

    public function markPaid(Invoice $invoice, string $reference, ?User $actor = null, ?CarbonImmutable $paidAt = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $reference, $actor, $paidAt) {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($invoice->type !== Invoice::INVOICE || $invoice->status !== Invoice::ISSUED) {
                throw BillingException::notPayable();
            }

            $invoice->forceFill([
                'status' => Invoice::PAID,
                'paid_at' => $paidAt ?? CarbonImmutable::now(),
                'payment_reference' => $reference,
            ])->save();

            $this->commissions->release($invoice);

            $this->audit->record(
                action: 'billing.invoice_paid',
                target: $invoice,
                old: ['status' => Invoice::ISSUED],
                new: ['status' => Invoice::PAID, 'number' => $invoice->number, 'reference' => $reference],
                actor: $actor,
                organizationId: $invoice->organization_id,
                partnerId: $invoice->partner_id,
            );

            return $invoice;
        });
    }
}
