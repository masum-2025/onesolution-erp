<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Accounting\Enums\DocumentType;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Models\PostingAccount;

/**
 * Accounting's public service for other modules that buy: a draft supplier
 * bill for what they received (Inventory: a goods receipt), its lines on the
 * clearing account the module declares (cleared_by: bills, e.g.
 * inventory.grni), so the bill clears what the receipt parked there.
 *
 *     $bill = app(Bills::class)->draftFor($company, [
 *         'party_id' => $vendorId, 'issue_date' => '2026-10-06', 'reference' => 'GRN-2026-00012',
 *         'clearing_key' => 'inventory.grni', 'cost_centre_id' => $branchId,
 *         'lines' => [['description' => 'Rice 25 kg', 'quantity_milli' => 10000, 'unit_price_minor' => 180000]],
 *     ], $actor);   // ['id' => ..., 'status' => 'draft', 'total_minor' => ...]
 *
 * The bill stays a draft: the person checks the supplier's VAT and amounts,
 * then submits it as any other bill (approval rules apply).
 */
class Bills
{
    public function __construct(
        private Books $books,
        private Documents $documents,
        private ChartOfAccounts $chart,
        private ModuleResolver $modules,
        private Parties $parties,
    ) {}

    /** Whether the company can take a bill now: Accounting on and set up. */
    public function available(Organization $company): bool
    {
        return $this->modules->isEnabled('accounting', $company) && $this->books->isSetUp($company);
    }

    /**
     * @param  array{party_id: string, issue_date: string, reference?: string|null, notes?: string|null, clearing_key: string, cost_centre_id?: string|null, lines: list<array{description: string, quantity_milli: int, unit_price_minor: int}>}  $data
     * @return array{id: string, status: string, total_minor: int}
     */
    public function draftFor(Organization $company, array $data, User $actor): array
    {
        if (! $this->modules->isEnabled('accounting', $company)) {
            throw AccountingException::moduleOff();
        }
        $this->books->assertSetUp($company);
        if (($this->chart->postingKeys()[$data['clearing_key']]['cleared_by'] ?? null) !== 'bills') {
            throw AccountingException::unknownPostingKey();
        }
        $account = $this->books->query(PostingAccount::class, $company)->where('posting_key', $data['clearing_key'])->value('account_id')
            ?? throw AccountingException::postingAccountMissing($data['clearing_key']);

        $bill = $this->documents->draft($company, DocumentType::Bill, [
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
            ], $data['lines']),
        ], $actor);

        return ['id' => $bill->getKey(), 'status' => $bill->status->value, 'total_minor' => (int) $bill->total_minor];
    }

    /**
     * The vendor for a business connection's provider (Compliance: invoices
     * that come over the connection): found by the connection, else made
     * from the provider's name.
     *
     * @param  array{name: string, phone?: string|null, email?: string|null}  $details
     */
    public function vendorForConnection(Organization $company, string $connectionId, array $details, User $actor): string
    {
        $this->assertAvailable($company);
        $party = $this->books->query(Party::class, $company)->where('connection_id', $connectionId)->first();
        if ($party !== null) {
            if (! $party->is_vendor) {
                $party = $this->parties->update($company, $party, $party->version, ['is_vendor' => true], $actor);
            }

            return $party->getKey();
        }
        $party = $this->parties->create($company, [
            'name' => mb_substr($details['name'], 0, 150), 'is_customer' => false, 'is_vendor' => true,
            'phone' => $details['phone'] ?? null, 'email' => $details['email'] ?? null,
        ], $actor);
        $party->forceFill(['connection_id' => $connectionId])->save();

        return $party->getKey();
    }

    /**
     * A draft bill for a service bought, its lines on the expense account
     * the module names (e.g. compliance.audit_fees). It stays a draft: the
     * person adds the input VAT code and submits it (approval rules apply).
     * Only in the books' own currency.
     *
     * @param  array{party_id: string, issue_date: string, due_date?: string|null, reference?: string|null, notes?: string|null, currency_code: string, expense_key: string, cost_centre_id?: string|null, lines: list<array{description: string, quantity_milli: int, unit_price_minor: int}>}  $data
     * @return array{id: string, status: string, total_minor: int}
     */
    public function draftExpense(Organization $company, array $data, User $actor): array
    {
        $this->assertAvailable($company);
        if ($data['currency_code'] !== $this->books->currency($company)) {
            throw AccountingException::currencyNotSupported($data['currency_code']);
        }
        if (($this->chart->postingKeys()[$data['expense_key']]['type'] ?? null) !== 'expense') {
            throw AccountingException::unknownPostingKey();
        }
        $account = $this->books->query(PostingAccount::class, $company)->where('posting_key', $data['expense_key'])->value('account_id')
            ?? throw AccountingException::postingAccountMissing($data['expense_key']);

        $bill = $this->documents->draft($company, DocumentType::Bill, [
            'party_id' => $data['party_id'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'reference' => isset($data['reference']) ? mb_substr($data['reference'], 0, 60) : null,
            'notes' => isset($data['notes']) ? mb_substr($data['notes'], 0, 1000) : null,
            'lines' => array_map(fn (array $line) => [
                'description' => mb_substr($line['description'], 0, 255),
                'quantity' => self::quantityText($line['quantity_milli']),
                'unit_price_minor' => $line['unit_price_minor'],
                'account_id' => $account,
                'cost_centre_id' => $data['cost_centre_id'] ?? null,
            ], $data['lines']),
        ], $actor);

        return ['id' => $bill->getKey(), 'status' => $bill->status->value, 'total_minor' => (int) $bill->total_minor];
    }

    private function assertAvailable(Organization $company): void
    {
        if (! $this->modules->isEnabled('accounting', $company)) {
            throw AccountingException::moduleOff();
        }
        $this->books->assertSetUp($company);
    }

    /** 12500 -> "12.5": the text quantity bills take. */
    private static function quantityText(int $milli): string
    {
        $whole = intdiv($milli, Documents::MILLI);
        $rest = $milli % Documents::MILLI;

        return $rest === 0 ? (string) $whole : $whole.'.'.rtrim(str_pad((string) $rest, 3, '0', STR_PAD_LEFT), '0');
    }
}
