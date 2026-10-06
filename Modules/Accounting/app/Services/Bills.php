<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Accounting\Enums\DocumentType;
use Modules\Accounting\Exceptions\AccountingException;
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

    /** 12500 -> "12.5": the text quantity bills take. */
    private static function quantityText(int $milli): string
    {
        $whole = intdiv($milli, Documents::MILLI);
        $rest = $milli % Documents::MILLI;

        return $rest === 0 ? (string) $whole : $whole.'.'.rtrim(str_pad((string) $rest, 3, '0', STR_PAD_LEFT), '0');
    }
}
