<?php

namespace Modules\Crm\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\Customers;
use Modules\Accounting\Services\TaxCodes;
use Modules\Crm\Exceptions\CrmException;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Deal;
use Modules\Crm\Models\Quote;
use Modules\Crm\Models\QuoteLine;
use Modules\Crm\Models\Sequence;
use Modules\Inventory\Services\Stock;

/**
 * Estimates and quotations.
 *
 *   draft -> sent -> accepted | declined        (a quotation)
 *   draft | sent -> converted                   (an estimate becomes a quotation)
 *
 * Lines are an Inventory item (its name, unit and price to start from, when
 * Inventory is on) or free text (labour, services); VAT from Accounting's
 * sales tax codes (when it keeps the books), inside or on top of the price
 * by rule crm.quote_prices_include_tax. Both the quote and each line carry
 * the company's own extra fields (crm_fields: quote, quote_line). A draft
 * can be changed; once sent, changing it makes it a draft again. Accepting
 * a quotation wins its deal and, when asked and Accounting keeps the books,
 * makes the customer and a draft invoice. Valid until: rule
 * crm.quote_valid_days after the issue day, unless given. Numbers per kind
 * and year from rule crm.number_prefixes (QT-2026-00001).
 */
class Quotes
{
    public const MILLI = 1000;

    public const MAX_LINES = 200;

    public function __construct(
        private Crm $crm,
        private Fields $fields,
        private Deals $deals,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by QuoteRequest.
     */
    public function create(Organization $company, string $unitId, array $data, User $actor): Quote
    {
        $contact = $this->contact($company, $data['contact_id']);
        $this->checkDeal($company, $data['deal_id'] ?? null, $contact);
        $inclusive = (bool) $this->rules->get('crm.quote_prices_include_tax', $this->contexts->forOrganization($company));
        $lines = $this->priceLines($company, $data['lines'], $inclusive);
        $extra = $this->fields->apply($company, 'quote', (array) ($data['extra'] ?? []), [], true);

        return $this->crm->transaction($company, function () use ($company, $unitId, $data, $actor, $contact, $inclusive, $lines, $extra) {
            $quote = new Quote;
            $issued = $data['issue_date'] ?? $this->crm->today($company)->toDateString();
            $quote->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $unitId, 'kind' => $data['kind'], 'status' => Quote::DRAFT, 'contact_id' => $contact->getKey(),
                'deal_id' => $data['deal_id'] ?? null, 'issue_date' => $issued, 'valid_until' => $data['valid_until'] ?? $this->validUntil($company, $issued),
                'subject' => $data['subject'] ?? null, 'notes' => $data['notes'] ?? null, 'terms' => $data['terms'] ?? null,
                'currency_code' => $this->crm->currency($company), 'prices_include_tax' => $inclusive, ...self::totals($lines),
                'extra' => $extra ?: null, 'created_by' => $actor->getKey(), 'version' => 1,
            ]);
            $quote->number = $this->nextNumber($company, $quote->kind, $issued);
            $quote->save();
            $this->writeLines($company, $quote, $lines);
            $this->audit->record('crm.quote_created', $quote, new: $this->values($quote), actor: $actor, organizationId: $company->getKey());

            return $quote;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields to change.
     */
    public function update(Organization $company, Quote $quote, int $baseVersion, array $data, User $actor): Quote
    {
        $lines = isset($data['lines']) ? $this->priceLines($company, $data['lines'], (bool) $quote->prices_include_tax) : null;

        return $this->crm->transaction($company, function () use ($company, $quote, $baseVersion, $data, $actor, $lines) {
            $quote = $this->locked($company, $quote, $baseVersion, [Quote::DRAFT, Quote::SENT]);
            $old = $this->values($quote);
            if (array_key_exists('contact_id', $data)) {
                $quote->contact_id = $this->contact($company, $data['contact_id'])->getKey();
            }
            if (array_key_exists('deal_id', $data)) {
                $this->checkDeal($company, $data['deal_id'], $this->contact($company, $quote->contact_id));
            }
            $quote->fill(array_intersect_key($data, array_flip(['deal_id', 'issue_date', 'valid_until', 'subject', 'notes', 'terms'])));
            if (array_key_exists('extra', $data)) {
                $quote->extra = $this->fields->apply($company, 'quote', (array) $data['extra'], (array) ($quote->extra ?? []), false) ?: null;
            }
            if ($lines !== null) {
                $this->crm->query(QuoteLine::class, $company)->where('quote_id', $quote->getKey())->delete();
                $this->writeLines($company, $quote, $lines);
                $quote->fill(self::totals($lines));
            }
            // A changed quote has to be sent again.
            $quote->status = Quote::DRAFT;
            $quote->sent_at = null;
            $quote->version++;
            $quote->save();
            $this->audit->record('crm.quote_updated', $quote, old: $old, new: $this->values($quote), actor: $actor, organizationId: $company->getKey());

            return $quote;
        });
    }

    public function delete(Organization $company, Quote $quote, int $baseVersion, User $actor): void
    {
        $this->crm->transaction($company, function () use ($company, $quote, $baseVersion, $actor) {
            $quote = $this->locked($company, $quote, $baseVersion, [Quote::DRAFT]);
            $this->audit->record('crm.quote_deleted', $quote, old: $this->values($quote), actor: $actor, organizationId: $company->getKey());
            $this->crm->query(QuoteLine::class, $company)->where('quote_id', $quote->getKey())->delete();
            $quote->delete();
        });
    }

    /** Marked as given to the customer (printed, emailed or handed over). */
    public function send(Organization $company, Quote $quote, int $baseVersion, User $actor): Quote
    {
        return $this->step($company, $quote, $baseVersion, [Quote::DRAFT, Quote::SENT], fn (Quote $quote) => $quote->forceFill(['status' => Quote::SENT, 'sent_at' => now()]), 'crm.quote_sent', $actor);
    }

    public function decline(Organization $company, Quote $quote, int $baseVersion, string $reason, User $actor): Quote
    {
        return $this->step($company, $quote, $baseVersion, [Quote::DRAFT, Quote::SENT], fn (Quote $quote) => $quote->forceFill(['status' => Quote::DECLINED, 'decline_reason' => $reason, 'decided_at' => now()]), 'crm.quote_declined', $actor);
    }

    /**
     * The customer said yes: its deal is won; with $invoice (and books kept),
     * the customer is made in Accounting and a draft invoice for the quote.
     */
    public function accept(Organization $company, Quote $quote, int $baseVersion, bool $invoice, User $actor): Quote
    {
        if ($quote->kind !== 'quotation') {
            throw CrmException::wrongStatus($quote->kind);
        }
        $customers = app(Customers::class);
        if ($invoice && ! ($this->modules->isEnabled('accounting', $company) && $customers->available($company))) {
            throw CrmException::accountingOff();
        }

        return $this->crm->transaction($company, function () use ($company, $quote, $baseVersion, $invoice, $actor, $customers) {
            $quote = $this->locked($company, $quote, $baseVersion, [Quote::DRAFT, Quote::SENT]);
            $old = $this->values($quote);
            if ($quote->deal_id !== null) {
                $this->deals->win($company, $quote->deal_id, $actor);
            }
            if ($invoice) {
                $contact = $this->crm->query(Contact::class, $company)->findOrFail($quote->contact_id);
                $party = $customers->forContact($company, $contact->getKey(), ['name' => $contact->company_name ?: $contact->name, 'phone' => $contact->phone, 'email' => $contact->email, 'address' => $contact->address], $actor);
                $lines = $this->crm->query(QuoteLine::class, $company)->where('quote_id', $quote->getKey())->orderBy('line_no')->get();
                // Invoices read prices by Accounting's own rule (VAT inside or on top).
                $booksInclusive = (bool) $this->rules->get('accounting.prices_include_tax', $this->contexts->forOrganization($company));
                $made = $customers->draftInvoice($company, [
                    'party_id' => $party, 'issue_date' => $this->crm->today($company)->toDateString(), 'reference' => $quote->number, 'income_key' => 'crm.sales',
                    'notes' => __('crm::crm.invoice_from', ['number' => $quote->number]), 'cost_centre_id' => $quote->unit_id,
                    // Each line at its price after discount (the discount folded into the price).
                    'lines' => $lines->map(fn (QuoteLine $line) => [
                        'description' => $line->description, 'quantity_milli' => $line->quantity_milli,
                        'unit_price_minor' => intdiv(($line->net_minor + ($booksInclusive ? $line->tax_minor : 0)) * self::MILLI + intdiv($line->quantity_milli, 2), $line->quantity_milli),
                        'tax_code_id' => $line->tax_code_id,
                    ])->all(),
                ], $actor);
                $quote->invoice_id = $made['id'];
            }
            $quote->forceFill(['status' => Quote::ACCEPTED, 'decided_at' => now(), 'version' => $quote->version + 1])->save();
            $this->audit->record('crm.quote_accepted', $quote, old: $old, new: $this->values($quote), actor: $actor, organizationId: $company->getKey());

            return $quote;
        });
    }

    /** An estimate becomes a quotation (a copy with its own number); the estimate is marked converted. */
    public function convert(Organization $company, Quote $estimate, int $baseVersion, User $actor): Quote
    {
        if ($estimate->kind !== 'estimate') {
            throw CrmException::wrongStatus($estimate->kind);
        }

        return $this->crm->transaction($company, function () use ($company, $estimate, $baseVersion, $actor) {
            $estimate = $this->locked($company, $estimate, $baseVersion, [Quote::DRAFT, Quote::SENT]);
            $today = $this->crm->today($company)->toDateString();
            $quotation = $estimate->replicate(['number', 'status', 'sent_at', 'decided_at', 'invoice_id', 'decline_reason']);
            $quotation->forceFill(['kind' => 'quotation', 'status' => Quote::DRAFT, 'from_quote_id' => $estimate->getKey(), 'issue_date' => $today,
                'valid_until' => $this->validUntil($company, $today), 'created_by' => $actor->getKey(), 'version' => 1]);
            $quotation->number = $this->nextNumber($company, 'quotation', $today);
            $quotation->save();
            foreach ($this->crm->query(QuoteLine::class, $company)->where('quote_id', $estimate->getKey())->orderBy('line_no')->get() as $line) {
                $copy = $line->replicate();
                $copy->quote_id = $quotation->getKey();
                $copy->save();
            }
            $estimate->forceFill(['status' => Quote::CONVERTED, 'decided_at' => now(), 'version' => $estimate->version + 1])->save();
            $this->audit->record('crm.quote_converted', $estimate, new: ['quotation_id' => $quotation->getKey(), 'number' => $quotation->number], actor: $actor, organizationId: $company->getKey());

            return $quotation;
        });
    }

    /**
     * @return Collection<int, QuoteLine>
     */
    public function linesOf(Organization $company, Quote $quote): Collection
    {
        return $this->crm->query(QuoteLine::class, $company)->where('quote_id', $quote->getKey())->orderBy('line_no')->get();
    }

    /** Whether a sent quote is past its last valid day. */
    public function expired(Organization $company, Quote $quote): bool
    {
        return in_array($quote->status, [Quote::DRAFT, Quote::SENT], true) && $quote->valid_until !== null && $quote->valid_until->toDateString() < $this->crm->today($company)->toDateString();
    }

    /**
     * Each line checked and priced: VAT split inside or on top, the line's
     * extra fields checked. Inventory items give their name and unit.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function priceLines(Organization $company, array $lines, bool $inclusive): array
    {
        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['lines' => __('crm::crm.validation.lines', ['max' => self::MAX_LINES])]);
        }
        $itemIds = array_values(array_unique(array_filter(array_column($lines, 'item_id'))));
        $items = [];
        $units = [];
        if ($itemIds !== [] && $this->modules->isEnabled('inventory', $company)) {
            $stock = app(Stock::class);
            $items = collect($stock->items($company, $itemIds))->keyBy('id')->all();
            $units = $stock->units($company);
        }
        $codes = array_values(array_unique(array_filter(array_column($lines, 'tax_code_id'))));
        $rates = $codes !== [] && $this->modules->isEnabled('accounting', $company) ? app(TaxCodes::class)->salesRates($company, $codes) : [];

        $errors = [];
        $priced = [];
        foreach (array_values($lines) as $index => $line) {
            $item = isset($line['item_id']) ? ($items[$line['item_id']] ?? null) : null;
            if (isset($line['item_id']) && $item === null) {
                $errors["lines.{$index}.item_id"] = __('crm::crm.validation.item');
            }
            $description = trim((string) ($line['description'] ?? '')) ?: ($item === null ? '' : trim($item['sku'].' '.$item['name']));
            if ($description === '') {
                $errors["lines.{$index}.description"] = __('crm::crm.validation.description');
            }
            $quantity = (int) ($line['quantity_milli'] ?? 0);
            if ($quantity <= 0) {
                $errors["lines.{$index}.quantity_milli"] = __('crm::crm.validation.quantity');
            }
            $price = (int) ($line['unit_price_minor'] ?? ($item['sale_price_minor'] ?? 0));
            $gross = self::amount($quantity, $price);
            $discount = (int) ($line['discount_minor'] ?? 0);
            if ($discount < 0 || $discount > $gross) {
                $errors["lines.{$index}.discount_minor"] = __('crm::crm.validation.discount');
            }
            $code = $line['tax_code_id'] ?? null;
            if ($code !== null && ! array_key_exists($code, $rates)) {
                $errors["lines.{$index}.tax_code_id"] = __('crm::crm.validation.tax_code');
            }
            $extra = $this->fields->check($company, 'quote_line', (array) ($line['extra'] ?? []), [], true, "lines.{$index}.extra");
            $errors += $extra['errors'];
            $split = TaxCodes::split(max($gross - $discount, 0), $rates[$code] ?? 0, $inclusive);
            $priced[] = [
                'item_id' => $item === null ? null : $item['id'], 'description' => mb_substr($description, 0, 255), 'quantity_milli' => $quantity,
                'unit' => $line['unit'] ?? ($item === null ? null : ($units[$item['unit_id']]['code'] ?? null)), 'unit_price_minor' => $price,
                'discount_minor' => $discount, 'tax_code_id' => $code, 'tax_rate_bp' => $rates[$code] ?? 0, 'gross_minor' => $gross,
                'net_minor' => $split['net'], 'tax_minor' => $split['tax'], 'total_minor' => $split['net'] + $split['tax'], 'extra' => $extra['values'] ?: null,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $priced;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{subtotal_minor: int, discount_minor: int, tax_minor: int, total_minor: int}
     */
    public static function totals(array $lines): array
    {
        return [
            'subtotal_minor' => array_sum(array_column($lines, 'gross_minor')),
            'discount_minor' => array_sum(array_column($lines, 'discount_minor')),
            'tax_minor' => array_sum(array_column($lines, 'tax_minor')),
            'total_minor' => array_sum(array_column($lines, 'total_minor')),
        ];
    }

    public static function amount(int $quantityMilli, int $unitPriceMinor): int
    {
        return intdiv($quantityMilli * $unitPriceMinor + intdiv(self::MILLI, 2), self::MILLI);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function step(Organization $company, Quote $quote, int $baseVersion, array $statuses, callable $change, string $action, User $actor): Quote
    {
        return $this->crm->transaction($company, function () use ($company, $quote, $baseVersion, $statuses, $change, $action, $actor) {
            $quote = $this->locked($company, $quote, $baseVersion, $statuses);
            $old = $this->values($quote);
            $change($quote);
            $quote->version++;
            $quote->save();
            $this->audit->record($action, $quote, old: $old, new: $this->values($quote), actor: $actor, organizationId: $company->getKey());

            return $quote;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(Organization $company, Quote $quote, array $lines): void
    {
        foreach ($lines as $index => $line) {
            $row = new QuoteLine;
            $row->fill([...array_diff_key($line, ['gross_minor' => true]), 'organization_id' => $company->getKey(), 'quote_id' => $quote->getKey(), 'line_no' => $index + 1]);
            $row->save();
        }
    }

    private function nextNumber(Organization $company, string $kind, string $on): string
    {
        $year = (int) substr($on, 0, 4);
        $sequence = $this->crm->query(Sequence::class, $company)->where('kind', $kind)->where('year', $year)->lockForUpdate()->first();
        if ($sequence === null) {
            $sequence = new Sequence;
            $sequence->fill(['organization_id' => $company->getKey(), 'kind' => $kind, 'year' => $year, 'last_number' => 0]);
        }
        $sequence->last_number++;
        $sequence->save();
        $prefixes = (array) $this->rules->get('crm.number_prefixes', $this->contexts->forOrganization($company));

        return sprintf('%s-%d-%05d', $prefixes[$kind] ?? strtoupper(substr($kind, 0, 2)), $year, $sequence->last_number);
    }

    private function validUntil(Organization $company, string $issued): string
    {
        $days = (int) $this->rules->get('crm.quote_valid_days', $this->contexts->forOrganization($company));

        return CarbonImmutable::parse($issued)->addDays(max($days, 0))->toDateString();
    }

    private function contact(Organization $company, string $id): Contact
    {
        $contact = $this->crm->query(Contact::class, $company)->whereKey($id)->first();
        if ($contact === null) {
            throw ValidationException::withMessages(['contact_id' => __('crm::crm.validation.contact')]);
        }
        if ($contact->anonymized_at !== null) {
            throw CrmException::anonymized();
        }

        return $contact;
    }

    private function checkDeal(Organization $company, ?string $dealId, Contact $contact): void
    {
        if ($dealId !== null && ! $this->crm->query(Deal::class, $company)->whereKey($dealId)->where('contact_id', $contact->getKey())->exists()) {
            throw ValidationException::withMessages(['deal_id' => __('crm::crm.validation.deal')]);
        }
    }

    /**
     * @param  list<string>  $statuses
     */
    private function locked(Organization $company, Quote $quote, int $baseVersion, array $statuses): Quote
    {
        /** @var Quote $fresh */
        $fresh = $this->crm->query(Quote::class, $company)->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw CrmException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }
        if (! in_array($fresh->status, $statuses, true)) {
            throw CrmException::wrongStatus($fresh->status);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Quote $quote): array
    {
        return [...$quote->only(['kind', 'number', 'status', 'contact_id', 'deal_id', 'total_minor', 'tax_minor', 'currency_code', 'invoice_id']), 'issue_date' => $quote->issue_date?->toDateString()];
    }
}
