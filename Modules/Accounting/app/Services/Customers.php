<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Accounting\Enums\DocumentStatus;
use Modules\Accounting\Enums\DocumentType;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Models\PostingAccount;

/**
 * Accounting's public service for modules that sell on credit (CRM): the
 * customer (party) for a contact, made once and found again by the
 * contact's id; a draft invoice for what a customer accepted, its lines on
 * the income account the module names; and what the customer owes.
 *
 *     $party = app(Customers::class)->forContact($company, $contactId, ['name' => 'Rahim Traders', 'phone' => '+88017…'], $actor);
 *     $invoice = app(Customers::class)->draftInvoice($company, [
 *         'party_id' => $party, 'issue_date' => '2026-11-05', 'reference' => 'QT-2026-00004', 'income_key' => 'crm.sales',
 *         'lines' => [['description' => 'Office chairs', 'quantity_milli' => 10000, 'unit_price_minor' => 650000, 'tax_code_id' => $vat]],
 *     ], $actor);
 */
class Customers
{
    public function __construct(
        private Books $books,
        private Parties $parties,
        private Documents $documents,
        private Receivables $receivables,
        private ModuleResolver $modules,
    ) {}

    /** Whether the company keeps books now: Accounting on and set up. */
    public function available(Organization $company): bool
    {
        return $this->modules->isEnabled('accounting', $company) && $this->books->isSetUp($company);
    }

    /** The customer already made for a contact, if any. */
    public function partyOf(Organization $company, string $contactId): ?string
    {
        if (! $this->available($company)) {
            return null;
        }

        return $this->books->query(Party::class, $company)->where('crm_contact_id', $contactId)->value('id');
    }

    /**
     * The contact's customer: found by the contact's id (and marked a
     * customer if it was only a vendor), else made from its details.
     *
     * @param  array{name: string, phone?: string|null, email?: string|null, address?: array<string, mixed>|null}  $details
     */
    public function forContact(Organization $company, string $contactId, array $details, User $actor): string
    {
        $this->assertAvailable($company);
        $party = $this->books->query(Party::class, $company)->where('crm_contact_id', $contactId)->first();
        if ($party !== null) {
            if (! $party->is_customer) {
                $party = $this->parties->update($company, $party, $party->version, ['is_customer' => true], $actor);
            }

            return $party->getKey();
        }
        $party = $this->parties->create($company, [
            'name' => mb_substr($details['name'], 0, 150), 'is_customer' => true, 'is_vendor' => false,
            'phone' => $details['phone'] ?? null, 'email' => $details['email'] ?? null, 'address' => $details['address'] ?? null,
        ], $actor);
        $party->forceFill(['crm_contact_id' => $contactId])->save();

        return $party->getKey();
    }

    /**
     * @param  array{party_id: string, issue_date: string, reference?: string|null, notes?: string|null, income_key: string, cost_centre_id?: string|null, lines: list<array{description: string, quantity_milli: int, unit_price_minor: int, tax_code_id?: string|null}>}  $data
     * @return array{id: string, status: string, total_minor: int}
     */
    public function draftInvoice(Organization $company, array $data, User $actor): array
    {
        $this->assertAvailable($company);
        $account = $this->books->query(PostingAccount::class, $company)->where('posting_key', $data['income_key'])->value('account_id')
            ?? throw AccountingException::postingAccountMissing($data['income_key']);

        $invoice = $this->documents->draft($company, DocumentType::Invoice, [
            'party_id' => $data['party_id'],
            'issue_date' => $data['issue_date'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'lines' => array_map(fn (array $line) => [
                'description' => mb_substr($line['description'], 0, 255),
                'quantity' => self::quantityText($line['quantity_milli']),
                'unit_price_minor' => $line['unit_price_minor'],
                'account_id' => $account,
                'cost_centre_id' => $data['cost_centre_id'] ?? null,
                'tax_code_id' => $line['tax_code_id'] ?? null,
            ], $data['lines']),
        ], $actor);

        return ['id' => $invoice->getKey(), 'status' => $invoice->status->value, 'total_minor' => (int) $invoice->total_minor];
    }

    /**
     * Sales invoices by id, in short (unknown ones left out): what other
     * modules show of the invoices they started (Projects).
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, number: string|null, status: string, total_minor: int, balance_minor: int, currency_code: string, issue_date: string|null, due_date: string|null}>
     */
    public function invoices(Organization $company, array $ids): array
    {
        if ($ids === [] || ! $this->available($company)) {
            return [];
        }

        return $this->books->query(Document::class, $company)->whereKey($ids)->where('type', DocumentType::Invoice->value)->get()
            ->mapWithKeys(fn (Document $invoice) => [$invoice->getKey() => [
                'id' => $invoice->getKey(),
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'total_minor' => (int) $invoice->total_minor,
                // A void or unposted invoice is owed nothing.
                'balance_minor' => in_array($invoice->status, [DocumentStatus::Posted, DocumentStatus::PartlyPaid], true) ? $invoice->balance() : 0,
                'currency_code' => $invoice->currency_code,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
            ]])->all();
    }

    /**
     * One sales invoice in full, with its lines: what another module sends
     * on (Projects: to a connected client). Null when unknown.
     *
     * @return array{id: string, number: string|null, status: string, issue_date: string|null, due_date: string|null, currency_code: string, prices_include_tax: bool, net_minor: int, tax_minor: int, total_minor: int, balance_minor: int, notes: string|null, lines: list<array{description: string, quantity_milli: int, unit_price_minor: int, net_minor: int, tax_rate_bp: int, tax_minor: int, total_minor: int}>}|null
     */
    public function invoiceFull(Organization $company, string $id): ?array
    {
        if (! $this->available($company)) {
            return null;
        }
        $invoice = $this->books->query(Document::class, $company)->whereKey($id)->where('type', DocumentType::Invoice->value)->first();
        if ($invoice === null) {
            return null;
        }
        $lines = $this->books->query(DocumentLine::class, $company)->where('document_id', $invoice->getKey())->orderBy('line_no')->get();

        return [
            'id' => $invoice->getKey(),
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency_code' => $invoice->currency_code,
            'prices_include_tax' => (bool) $invoice->prices_include_tax,
            'net_minor' => (int) $invoice->net_minor,
            'tax_minor' => (int) $invoice->tax_minor,
            'total_minor' => (int) $invoice->total_minor,
            'balance_minor' => in_array($invoice->status, [DocumentStatus::Posted, DocumentStatus::PartlyPaid], true) ? $invoice->balance() : 0,
            'notes' => $invoice->notes,
            'lines' => $lines->map(fn (DocumentLine $line) => [
                'description' => $line->description,
                'quantity_milli' => (int) $line->quantity_milli,
                'unit_price_minor' => (int) $line->unit_price_minor,
                'net_minor' => (int) $line->amount_minor,
                'tax_rate_bp' => (int) $line->tax_rate_bp,
                'tax_minor' => (int) $line->tax_minor,
                'total_minor' => (int) $line->amount_minor + (int) $line->tax_minor,
            ])->values()->all(),
        ];
    }

    /** What the contact's customer owes today (0 without books or a customer). */
    public function owedBy(Organization $company, string $contactId): int
    {
        $party = $this->partyOf($company, $contactId);

        return $party === null ? 0 : $this->receivables->balanceOf($company, $this->books->query(Party::class, $company)->findOrFail($party), 'sales');
    }

    private function assertAvailable(Organization $company): void
    {
        if (! $this->modules->isEnabled('accounting', $company)) {
            throw AccountingException::moduleOff();
        }
        $this->books->assertSetUp($company);
    }

    /** 12500 -> "12.5": the text quantity documents take. */
    private static function quantityText(int $milli): string
    {
        $whole = intdiv($milli, Documents::MILLI);
        $rest = $milli % Documents::MILLI;

        return $rest === 0 ? (string) $whole : $whole.'.'.rtrim(str_pad((string) $rest, 3, '0', STR_PAD_LEFT), '0');
    }
}
