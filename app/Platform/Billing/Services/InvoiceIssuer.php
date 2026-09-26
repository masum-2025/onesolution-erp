<?php

namespace App\Platform\Billing\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Models\InvoiceLine;
use App\Platform\Billing\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Issues an invoice or credit note: totals from integer lines, tax at the
 * given rate (half up), a gap-free number, frozen seller and buyer, and an
 * audit entry in the client's log (client documents) or the partner's.
 */
class InvoiceIssuer
{
    public function __construct(private InvoiceNumbers $numbers, private AuditLogger $audit) {}

    public function issue(InvoiceDraft $draft, ?User $actor = null): Invoice
    {
        return DB::transaction(function () use ($draft, $actor) {
            $now = CarbonImmutable::now();
            $subtotal = array_sum(array_map(fn (array $line) => $line['quantity'] * $line['unit_amount_minor'], $draft->lines));
            $tax = Money::share($subtotal, $draft->taxRateBp);

            $invoice = new Invoice;
            $invoice->forceFill([
                'number' => $this->numbers->next($draft->type, $now),
                'type' => $draft->type,
                'credits_invoice_id' => $draft->credits?->getKey(),
                'billed_to' => $draft->billedTo,
                'partner_id' => $draft->partner->getKey(),
                'organization_id' => $draft->organization?->getKey(),
                'brand_partner_id' => $draft->brandPartner->getKey(),
                'billing_mode' => $draft->billingMode,
                'billing_key' => $draft->billingKey,
                'currency_code' => $draft->currency,
                'period_start' => $draft->periodStart?->toDateString(),
                'period_end' => $draft->periodEnd?->toDateString(),
                'subtotal_minor' => $subtotal,
                'tax_rate_bp' => $draft->taxRateBp,
                'tax_minor' => $tax,
                'total_minor' => $subtotal + $tax,
                'status' => Invoice::ISSUED,
                'issued_at' => $now,
                'due_at' => $draft->type === Invoice::INVOICE ? $now->addDays($draft->paymentTermsDays) : null,
                'reason' => $draft->reason,
                'seller' => $draft->credits?->seller ?? $this->seller(),
                'buyer' => $draft->credits?->buyer ?? $this->buyer($draft),
            ])->save();

            foreach (array_values($draft->lines) as $position => $line) {
                (new InvoiceLine)->forceFill([
                    'invoice_id' => $invoice->getKey(),
                    'position' => $position + 1,
                    'organization_id' => $line['organization_id'],
                    'plan_key' => $line['plan_key'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_amount_minor' => $line['unit_amount_minor'],
                    'amount_minor' => $line['quantity'] * $line['unit_amount_minor'],
                ])->save();
            }

            $this->audit->record(
                action: $invoice->isCreditNote() ? 'billing.credit_note_issued' : 'billing.invoice_issued',
                target: $invoice,
                new: [
                    'number' => $invoice->number,
                    'currency' => $invoice->currency_code,
                    'total_minor' => $invoice->total_minor,
                    'credits' => $draft->credits?->number,
                ],
                reason: $draft->reason,
                actor: $actor,
                organizationId: $invoice->organization_id,
                partnerId: $invoice->partner_id,
            );

            return $invoice;
        });
    }

    /**
     * @return array<string, string|null>
     */
    private function seller(): array
    {
        return array_map(fn ($value) => $value === null || $value === '' ? null : (string) $value, (array) config('billing.issuer'));
    }

    /**
     * @return array<string, mixed>
     */
    private function buyer(InvoiceDraft $draft): array
    {
        if ($draft->organization !== null) {
            return [
                'name' => (array) $draft->organization->name,
                'country_code' => $draft->organization->country_code,
            ];
        }

        return ['name' => ['en' => $draft->partner->name], 'country_code' => null];
    }
}
