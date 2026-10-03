<?php

namespace Modules\Accounting\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Ledger\LedgerEntry;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Accounting\Ledger\PostedJournal;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\PostingAccount;

/**
 * Accounting's public service: the only way other modules (Payroll,
 * Inventory, sales) put entries into a company's books.
 *
 *     app(Ledger::class)->post($company, new LedgerEntry(
 *         opId: "payroll-run-{$run->id}", entryDate: '2026-10-31', narration: 'Salaries October',
 *         sourceModule: 'payroll', sourceType: 'run', sourceId: $run->id, currency: 'BDT',
 *         lines: [LedgerLine::debit('payroll.salary_expense', 5000000), LedgerLine::credit('payroll.salaries_payable', 5000000)],
 *     ));
 *
 * Call it from a queued job (no tenant context) or from a request at the
 * company itself; a branch-level context cannot write the company's books.
 * Accounting must be on and set up at the company, the period open, and
 * every posting key mapped to an account. Above the approval amount the
 * journal waits for a person to approve it (status pending_approval).
 */
class Ledger
{
    public function __construct(
        private Books $books,
        private Journals $journals,
        private ModuleResolver $modules,
    ) {}

    public function post(Organization $company, LedgerEntry $entry): PostedJournal
    {
        $this->books->assertKeepsBooks($company);
        if (! $this->modules->isEnabled('accounting', $company)) {
            throw AccountingException::moduleOff();
        }
        $this->books->assertSetUp($company);
        if ($entry->currency !== $this->books->currency($company)) {
            throw AccountingException::currencyNotSupported($entry->currency);
        }

        $existing = $this->find($company, $entry->opId);
        if ($existing !== null) {
            return $existing;
        }

        $accounts = $this->books->query(PostingAccount::class, $company)
            ->whereIn('posting_key', array_map(fn (LedgerLine $line) => $line->postingKey, $entry->lines))
            ->pluck('account_id', 'posting_key');

        $lines = array_map(fn (LedgerLine $line) => [
            'account_id' => $accounts[$line->postingKey] ?? throw AccountingException::postingAccountMissing($line->postingKey),
            'cost_centre_id' => $line->costCentreId,
            'debit_minor' => $line->debitMinor,
            'credit_minor' => $line->creditMinor,
            'memo' => $line->memo,
        ], $entry->lines);

        try {
            return $this->books->transaction($company, function () use ($company, $entry, $lines) {
                $journal = $this->journals->draft($company, ['entry_date' => $entry->entryDate, 'narration' => $entry->narration, 'lines' => $lines], null, [
                    'module' => $entry->sourceModule, 'type' => $entry->sourceType, 'id' => $entry->sourceId, 'op_id' => $entry->opId,
                ]);

                return PostedJournal::of($this->journals->submit($company, $journal, null, null));
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The same entry arrived twice at once: the other one was saved.
            return $this->find($company, $entry->opId) ?? throw $exception;
        }
    }

    private function find(Organization $company, string $opId): ?PostedJournal
    {
        $journal = $this->books->query(Journal::class, $company)->where('op_id', $opId)->first();

        return $journal === null ? null : PostedJournal::of($journal);
    }
}
