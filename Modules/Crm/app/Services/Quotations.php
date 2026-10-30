<?php

namespace Modules\Crm\Services;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Accounting\Services\Customers;
use Modules\Crm\Models\Quote;

/**
 * CRM's public service for modules that price work for a client (Projects):
 * a draft estimate or quotation for a contact, made by CRM's own rules
 * (numbers, VAT, validity, extra fields), and short summaries of quotes by
 * id. Sending, accepting (and the invoice it makes) stay in CRM's screens;
 * other modules keep only the quote's id.
 *
 *     $quote = app(Quotations::class)->draft($company, $branchId, $contactId, 'quotation', 'BSCI renewal', [
 *         ['description' => 'Audit coordination', 'quantity_milli' => 1000, 'unit_price_minor' => 4500000],
 *     ], $user);
 *     $summaries = app(Quotations::class)->many($company, [$quote['id']]);
 */
class Quotations
{
    public function __construct(private Crm $crm, private Quotes $quotes, private ModuleResolver $modules) {}

    public function available(Organization $company): bool
    {
        return $this->modules->isEnabled('crm', $company);
    }

    /**
     * @param  'estimate'|'quotation'  $kind
     * @param  list<array{description: string, quantity_milli: int, unit_price_minor: int, discount_minor?: int, tax_code_id?: string|null}>  $lines
     * @return array{id: string, kind: string, number: string|null, status: string, total_minor: int, currency_code: string, invoice_id: string|null, issue_date: string|null}
     */
    public function draft(Organization $company, string $unitId, string $contactId, string $kind, ?string $subject, array $lines, User $actor, ?string $notes = null): array
    {
        $quote = $this->quotes->create($company, $unitId, [
            'kind' => $kind,
            'contact_id' => $contactId,
            'subject' => $subject === null ? null : mb_substr($subject, 0, 150),
            'notes' => $notes,
            'lines' => array_map(fn (array $line) => [
                'description' => mb_substr($line['description'], 0, 255),
                'quantity_milli' => $line['quantity_milli'],
                'unit_price_minor' => $line['unit_price_minor'],
                'discount_minor' => $line['discount_minor'] ?? 0,
                'tax_code_id' => $line['tax_code_id'] ?? null,
            ], $lines),
        ], $actor);

        return self::shape($quote);
    }

    /**
     * Quotes by id (unknown ones left out).
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, kind: string, number: string|null, status: string, total_minor: int, currency_code: string, invoice_id: string|null, issue_date: string|null}>
     */
    public function many(Organization $company, array $ids): array
    {
        if ($ids === [] || ! $this->available($company)) {
            return [];
        }

        return $this->crm->query(Quote::class, $company)->whereKey($ids)->get()
            ->mapWithKeys(fn (Quote $quote) => [$quote->getKey() => self::shape($quote)])->all();
    }

    /**
     * One quote in full, as the customer would see it: dates, terms, every line
     * and the totals (for sending it to a connected client). Null when unknown.
     *
     * @return array<string, mixed>|null
     */
    public function full(Organization $company, string $id): ?array
    {
        $quote = $this->available($company) ? $this->crm->query(Quote::class, $company)->find($id) : null;
        if ($quote === null) {
            return null;
        }

        return [
            ...self::shape($quote),
            'valid_until' => $quote->valid_until?->toDateString(),
            'subject' => $quote->subject,
            'notes' => $quote->notes,
            'terms' => $quote->terms,
            'prices_include_tax' => (bool) $quote->prices_include_tax,
            'subtotal_minor' => (int) $quote->subtotal_minor,
            'discount_minor' => (int) $quote->discount_minor,
            'tax_minor' => (int) $quote->tax_minor,
            'version' => (int) $quote->version,
            'lines' => $this->quotes->linesOf($company, $quote)->map(fn ($line) => [
                'description' => $line->description,
                'quantity_milli' => (int) $line->quantity_milli,
                'unit' => $line->unit,
                'unit_price_minor' => (int) $line->unit_price_minor,
                'discount_minor' => (int) $line->discount_minor,
                'tax_rate_bp' => (int) $line->tax_rate_bp,
                'net_minor' => (int) $line->net_minor,
                'tax_minor' => (int) $line->tax_minor,
                'total_minor' => (int) $line->total_minor,
            ])->values()->all(),
        ];
    }

    /** Marked as given to the customer (a draft becomes sent; a sent one stays). */
    public function markSent(Organization $company, string $id, User $actor): void
    {
        $quote = $this->crm->query(Quote::class, $company)->findOrFail($id);
        $this->quotes->send($company, $quote, $quote->version, $actor);
    }

    /**
     * The customer accepted it (e.g. over a business connection): CRM's own
     * accepting, with the draft invoice when Accounting keeps the books.
     */
    public function accept(Organization $company, string $id, User $actor): array
    {
        $quote = $this->crm->query(Quote::class, $company)->findOrFail($id);
        $books = app(Customers::class)->available($company);

        return self::shape($this->quotes->accept($company, $quote, $quote->version, $books, $actor));
    }

    /** The customer declined it, with their reason. */
    public function decline(Organization $company, string $id, string $reason, User $actor): array
    {
        $quote = $this->crm->query(Quote::class, $company)->findOrFail($id);

        return self::shape($this->quotes->decline($company, $quote, $quote->version, $reason, $actor));
    }

    /** @return array{id: string, kind: string, number: string|null, status: string, total_minor: int, currency_code: string, invoice_id: string|null, issue_date: string|null} */
    private static function shape(Quote $quote): array
    {
        return [
            'id' => $quote->getKey(),
            'kind' => $quote->kind,
            'number' => $quote->number,
            'status' => $quote->status,
            'total_minor' => (int) $quote->total_minor,
            'currency_code' => $quote->currency_code,
            'invoice_id' => $quote->invoice_id,
            'issue_date' => $quote->issue_date?->toDateString(),
        ];
    }
}
