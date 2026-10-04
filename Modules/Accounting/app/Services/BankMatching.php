<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\BankLine;
use Modules\Accounting\Models\BankMatch;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;

/**
 * Setting statement lines against what the books say happened on the same
 * account: posted journal lines whose debit minus credit equals the line's
 * amount (one line, or several adding up to it). The system proposes a pair
 * when exactly one book line has the same amount within the company's days
 * (rule accounting.bank_match_days) and that book line fits no other
 * statement line. A statement line the books do not have yet (bank charges,
 * interest) becomes a journal entry from here, sent like any other.
 * Lines inside a finished reconciliation stay as they are.
 */
class BankMatching
{
    /** Most book lines listed or offered at once. */
    public const MAX_BOOK_LINES = 500;

    public function __construct(
        private Books $books,
        private Journals $journals,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * Posted lines of the account the bank has not shown yet (or all of them),
     * newest first: id, journal, date, words, and the amount on the account.
     *
     * @return Collection<int, array{id: string, journal_id: string, number: string|null, entry_date: string, narration: string, memo: string|null, amount_minor: int, bank_line_id: string|null}>
     */
    public function bookLines(Organization $company, Account $account, bool $unmatchedOnly = true, ?string $to = null): Collection
    {
        $matched = $this->books->query(BankMatch::class, $company)->select('journal_line_id');
        $lines = $this->books->query(JournalLine::class, $company)
            ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->where('acc_journals.status', JournalStatus::Posted->value)
            ->where('acc_journal_lines.account_id', $account->getKey())
            ->when($unmatchedOnly, fn ($query) => $query->whereNotIn('acc_journal_lines.id', $matched))
            ->when($to !== null, fn ($query) => $query->where('acc_journals.entry_date', '<=', $to))
            ->orderByDesc('acc_journals.entry_date')->orderByDesc('acc_journal_lines.id')
            ->limit(self::MAX_BOOK_LINES)
            ->select(['acc_journal_lines.id', 'acc_journal_lines.journal_id', 'acc_journal_lines.debit_minor', 'acc_journal_lines.credit_minor', 'acc_journal_lines.memo',
                'acc_journals.number', 'acc_journals.entry_date', 'acc_journals.narration'])
            ->get();
        $banks = $unmatchedOnly ? collect() : $this->books->query(BankMatch::class, $company)->whereIn('journal_line_id', $lines->pluck('id')->all())->pluck('bank_line_id', 'journal_line_id');

        return $lines->map(fn (JournalLine $line) => [
            'id' => (string) $line->id,
            'journal_id' => (string) $line->journal_id,
            'number' => $line->number,
            'entry_date' => CarbonImmutable::parse($line->entry_date)->toDateString(),
            'narration' => (string) $line->narration,
            'memo' => $line->memo,
            'amount_minor' => $line->debit_minor - $line->credit_minor,
            'bank_line_id' => $banks[$line->id] ?? null,
        ])->values();
    }

    /**
     * Proposed pairs for statement lines not matched yet: statement line id =>
     * the one book line that fits it and nothing else.
     *
     * @param  Collection<int, BankLine>  $bankLines
     * @return array<string, string>
     */
    public function suggestions(Organization $company, Account $account, Collection $bankLines): array
    {
        $open = $bankLines->filter(fn (BankLine $line) => $line->matched_minor === 0 && ! $line->isLocked());
        if ($open->isEmpty()) {
            return [];
        }
        $days = (int) $this->rules->get('accounting.bank_match_days', $this->contexts->forOrganization($company));
        $book = $this->bookLines($company, $account);

        $fits = [];
        foreach ($open as $line) {
            $fits[$line->getKey()] = $book->filter(fn (array $entry) => $entry['amount_minor'] === $line->amount_minor
                && abs(CarbonImmutable::parse($entry['entry_date'], 'UTC')->diffInDays($line->line_date, false)) <= $days)->pluck('id')->all();
        }
        $wanted = array_count_values(array_merge(...array_values($fits)));

        $pairs = [];
        foreach ($fits as $lineId => $candidates) {
            if (count($candidates) === 1 && $wanted[$candidates[0]] === 1) {
                $pairs[$lineId] = $candidates[0];
            }
        }

        return $pairs;
    }

    /**
     * Set book lines against a statement line not matched yet; together they
     * must come to its amount exactly.
     *
     * @param  list<string>  $journalLineIds
     */
    public function match(Organization $company, BankLine $line, array $journalLineIds, User $actor): BankLine
    {
        return $this->books->transaction($company, function () use ($company, $line, $journalLineIds, $actor) {
            $line = $this->lockOpen($company, $line);
            if ($line->matched_minor !== 0) {
                throw AccountingException::bankLineMatched();
            }

            $ids = array_values(array_unique($journalLineIds));
            $book = $this->books->query(JournalLine::class, $company)
                ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
                ->whereIn('acc_journal_lines.id', $ids)
                ->where('acc_journal_lines.account_id', $line->account_id)
                ->where('acc_journals.status', JournalStatus::Posted->value)
                ->whereNotIn('acc_journal_lines.id', $this->books->query(BankMatch::class, $company)->select('journal_line_id'))
                ->select(['acc_journal_lines.id', 'acc_journal_lines.debit_minor', 'acc_journal_lines.credit_minor'])
                ->get();
            if ($ids === [] || $book->count() !== count($ids)) {
                throw ValidationException::withMessages(['journal_line_ids' => __('accounting::accounting.bank.match_lines')]);
            }
            $sum = (int) $book->sum(fn (JournalLine $entry) => $entry->debit_minor - $entry->credit_minor);
            if ($sum !== $line->amount_minor) {
                throw ValidationException::withMessages(['journal_line_ids' => __('accounting::accounting.bank.match_amount')]);
            }

            foreach ($book as $entry) {
                $match = new BankMatch;
                $match->fill([
                    'organization_id' => $company->getKey(), 'bank_line_id' => $line->getKey(), 'journal_line_id' => (string) $entry->id,
                    'amount_minor' => $entry->debit_minor - $entry->credit_minor, 'created_by' => $actor->getKey(),
                ])->save();
            }
            $line->forceFill(['matched_minor' => $sum])->save();
            $this->audit->record('accounting.bank_matched', $line, new: ['journal_lines' => $ids, 'amount_minor' => $sum], actor: $actor, organizationId: $company->getKey());

            return $line;
        });
    }

    public function unmatch(Organization $company, BankLine $line, User $actor): BankLine
    {
        return $this->books->transaction($company, function () use ($company, $line, $actor) {
            $line = $this->lockOpen($company, $line);
            $ids = $this->books->query(BankMatch::class, $company)->where('bank_line_id', $line->getKey())->pluck('journal_line_id')->all();
            $this->books->query(BankMatch::class, $company)->where('bank_line_id', $line->getKey())->delete();
            $line->forceFill(['matched_minor' => 0])->save();
            $this->audit->record('accounting.bank_unmatched', $line, old: ['journal_lines' => $ids], actor: $actor, organizationId: $company->getKey());

            return $line;
        });
    }

    /** Take every proposed pair of the account at once. */
    public function autoMatch(Organization $company, Account $account, User $actor): int
    {
        $lines = $this->books->query(BankLine::class, $company)->where('account_id', $account->getKey())
            ->whereNull('reconciliation_id')->where('matched_minor', 0)->get();
        $count = 0;
        foreach ($this->suggestions($company, $account, $lines) as $lineId => $journalLineId) {
            $this->match($company, $lines->firstWhere('id', $lineId), [$journalLineId], $actor);
            $count++;
        }

        return $count;
    }

    /**
     * Write what the books are missing (bank charges, interest, a transfer)
     * as a journal entry: the statement line's account against the account
     * chosen, dated the statement day. It is sent like any entry; posted at
     * once it is matched straight away, otherwise after approval.
     *
     * @param  array{account_id: string, narration: string, cost_centre_id?: string|null}  $data
     */
    public function entry(Organization $company, BankLine $line, array $data, User $actor): Journal
    {
        return $this->books->transaction($company, function () use ($company, $line, $data, $actor) {
            $line = $this->lockOpen($company, $line);
            if ($line->matched_minor !== 0) {
                throw AccountingException::bankLineMatched();
            }
            if ($data['account_id'] === $line->account_id) {
                throw ValidationException::withMessages(['account_id' => __('accounting::accounting.bank.entry_same_account')]);
            }

            $amount = abs($line->amount_minor);
            $in = $line->amount_minor > 0;
            $journal = $this->journals->draft($company, [
                'entry_date' => $line->line_date->toDateString(),
                'narration' => $data['narration'],
                'lines' => [
                    ['account_id' => $line->account_id, 'cost_centre_id' => $data['cost_centre_id'] ?? null, 'debit_minor' => $in ? $amount : 0, 'credit_minor' => $in ? 0 : $amount, 'memo' => mb_substr($line->description, 0, 255)],
                    ['account_id' => $data['account_id'], 'cost_centre_id' => $data['cost_centre_id'] ?? null, 'debit_minor' => $in ? 0 : $amount, 'credit_minor' => $in ? $amount : 0, 'memo' => $line->reference],
                ],
            ], $actor);
            // The day comes from the bank, not from the person: the backdating window does not apply.
            $journal = $this->journals->submit($company, $journal, $journal->version, $actor, checkDate: false);

            if ($journal->status === JournalStatus::Posted) {
                $bankSide = $this->books->linesOf($company, $journal)->get()->firstWhere('account_id', $line->account_id);
                $this->match($company, $line, [(string) $bankSide->getKey()], $actor);
            }

            return $journal;
        });
    }

    private function lockOpen(Organization $company, BankLine $line): BankLine
    {
        /** @var BankLine $fresh */
        $fresh = $this->books->query(BankLine::class, $company)->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->isLocked()) {
            throw AccountingException::bankLineReconciled();
        }

        return $fresh;
    }
}
