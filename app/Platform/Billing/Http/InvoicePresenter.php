<?php

namespace App\Platform\Billing\Http;

use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Models\InvoiceLine;
use App\Platform\Billing\Services\CreditNotes;
use App\Platform\Branding\BrandResolver;

/**
 * Invoices and credit notes for the partner console and the client's
 * billing screen: a short form for lists, the full document for one.
 */
class InvoicePresenter
{
    public function __construct(private BrandResolver $brands, private CreditNotes $credits) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getKey(),
            'number' => $invoice->number,
            'type' => $invoice->type,
            // "overdue" is an issued invoice past its due date.
            'status' => $invoice->isOverdue() ? 'overdue' : $invoice->status,
            'billed_to' => $invoice->billed_to,
            'billing_mode' => $invoice->billing_mode,
            'buyer' => $this->name($invoice->buyer['name'] ?? null),
            'organization_id' => $invoice->organization_id,
            'currency' => $invoice->currency_code,
            'total_minor' => $invoice->total_minor,
            'period_start' => $invoice->period_start?->toDateString(),
            'period_end' => $invoice->period_end?->toDateString(),
            'issued_at' => $invoice->issued_at->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'credits' => $invoice->credits_invoice_id === null ? null : ['id' => $invoice->credits_invoice_id, 'number' => $invoice->credits?->number],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'creditNotes', 'brandPartner', 'credits']);
        $brand = $this->brands->for($invoice->brandPartner);

        return [
            ...$this->summary($invoice),
            'lines' => $invoice->lines->map(fn (InvoiceLine $line) => [
                'description' => $line->text(),
                'quantity' => $line->quantity,
                'unit_amount_minor' => $line->unit_amount_minor,
                'amount_minor' => $line->amount_minor,
            ])->values(),
            'subtotal_minor' => $invoice->subtotal_minor,
            'tax_rate_bp' => $invoice->tax_rate_bp,
            'tax_minor' => $invoice->tax_minor,
            'seller' => $invoice->seller,
            'buyer_details' => ['name' => $this->name($invoice->buyer['name'] ?? null), 'country_code' => $invoice->buyer['country_code'] ?? null],
            'brand' => [
                'name' => $brand['name'],
                'primary_color' => $brand['primary_color'],
                'logo_url' => $brand['logo_url'],
                'support_email' => $brand['support_email'],
            ],
            'payment_reference' => $invoice->payment_reference,
            'reason' => $invoice->reason,
            'credit_notes' => $invoice->creditNotes->map(fn (Invoice $note) => [
                'id' => $note->getKey(),
                'number' => $note->number,
                'total_minor' => $note->total_minor,
                'issued_at' => $note->issued_at->toIso8601String(),
            ])->values(),
            'creditable_minor' => $invoice->isCreditNote() ? 0 : $this->credits->remaining($invoice),
        ];
    }

    /**
     * @param  array<string, string>|string|null  $name
     */
    private function name(array|string|null $name): ?string
    {
        if (! is_array($name)) {
            return $name;
        }

        return $name[app()->getLocale()] ?? $name['en'] ?? (reset($name) ?: null);
    }
}
