<?php

namespace Modules\Crm\Services;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
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
