<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\BankLine;
use Modules\Accounting\Models\Reconciliation;

/**
 * Agreeing an account with its statement up to a day.
 *
 * The statement side: its balance before the period (the last finished
 * reconciliation's, or the first time the one printed on the statement)
 * plus every statement line up to the day must equal the balance the
 * statement shows, and every one of those lines must be matched to the
 * books. The books side is shown alongside: the account's balance, and
 * entries the bank has not shown yet (cheques not cleared). Finishing locks
 * those statement lines; only the latest reconciliation reopens, with a
 * reason.
 */
class Reconciliations
{
    public function __construct(
        private Books $books,
        private Reports $reports,
        private BankMatching $matching,
        private AuditLogger $audit,
    ) {}

    public function latest(Organization $company, Account $account): ?Reconciliation
    {
        return $this->books->query(Reconciliation::class, $company)->where('account_id', $account->getKey())
            ->where('status', Reconciliation::FINISHED)->orderByDesc('statement_date')->first();
    }

    /**
     * Where a reconciliation up to $date with the statement balance $balance
     * stands now.
     *
     * @return array<string, mixed>
     */
    public function preview(Organization $company, Account $account, string $date, int $balance, ?int $opening): array
    {
        $latest = $this->latest($company, $account);
        if ($latest !== null && $date <= $latest->statement_date->toDateString()) {
            throw ValidationException::withMessages(['statement_date' => __('accounting::accounting.bank.date_after', ['date' => $latest->statement_date->toDateString()])]);
        }
        if ($latest === null && $opening === null) {
            throw ValidationException::withMessages(['opening_balance_minor' => __('accounting::accounting.bank.opening_needed')]);
        }
        $start = $latest?->statement_balance_minor ?? (int) $opening;

        $lines = $this->openLines($company, $account, $date);
        $unmatched = $lines->filter(fn (BankLine $line) => ! $line->isMatched());
        $cleared = $start + (int) $lines->sum('amount_minor');

        $totals = $this->reports->totals($company, null, $date, null, [$account->getKey()])[$account->getKey()] ?? ['debit' => 0, 'credit' => 0];
        $book = $totals['debit'] - $totals['credit'];
        $outstanding = $this->matching->bookLines($company, $account, true, $date);

        return [
            'statement_date' => $date,
            'opening_balance_minor' => $start,
            'first' => $latest === null,
            'statement_balance_minor' => $balance,
            'lines' => $lines->count(),
            'money_in_minor' => (int) $lines->where('amount_minor', '>', 0)->sum('amount_minor'),
            'money_out_minor' => (int) -$lines->where('amount_minor', '<', 0)->sum('amount_minor'),
            'cleared_balance_minor' => $cleared,
            'difference_minor' => $balance - $cleared,
            'unmatched' => $unmatched->count(),
            'book_balance_minor' => $book,
            'outstanding' => $outstanding->count(),
            'outstanding_minor' => (int) $outstanding->sum('amount_minor'),
            'can_finish' => $unmatched->isEmpty() && $balance === $cleared,
        ];
    }

    public function finish(Organization $company, Account $account, string $date, int $balance, ?int $opening, User $actor): Reconciliation
    {
        return $this->books->transaction($company, function () use ($company, $account, $date, $balance, $opening, $actor) {
            // Lines being counted stay as they are until this is written.
            $this->openLines($company, $account, $date, lock: true);
            $state = $this->preview($company, $account, $date, $balance, $opening);
            if ($state['unmatched'] > 0) {
                throw AccountingException::bankLinesUnmatched($state['unmatched']);
            }
            if ($state['difference_minor'] !== 0) {
                throw AccountingException::statementDifference();
            }

            $reconciliation = new Reconciliation;
            $reconciliation->fill([
                'organization_id' => $company->getKey(),
                'account_id' => $account->getKey(),
                'statement_date' => $date,
                'opening_balance_minor' => $state['opening_balance_minor'],
                'statement_balance_minor' => $balance,
                'book_balance_minor' => $state['book_balance_minor'],
                'status' => Reconciliation::FINISHED,
                'finished_by' => $actor->getKey(),
                'finished_at' => now(),
            ])->save();
            $this->books->query(BankLine::class, $company)->where('account_id', $account->getKey())
                ->whereNull('reconciliation_id')->where('line_date', '<=', $date)
                ->update(['reconciliation_id' => $reconciliation->getKey()]);

            $this->audit->record('accounting.reconciled', $reconciliation, new: [
                'account_id' => $account->getKey(), 'statement_date' => $date, 'statement_balance_minor' => $balance,
                'book_balance_minor' => $state['book_balance_minor'], 'lines' => $state['lines'],
            ], actor: $actor, organizationId: $company->getKey());

            return $reconciliation;
        });
    }

    /** Undo the latest reconciliation of its account: its lines can change again. */
    public function reopen(Organization $company, Reconciliation $reconciliation, string $reason, User $actor): Reconciliation
    {
        return $this->books->transaction($company, function () use ($company, $reconciliation, $reason, $actor) {
            /** @var Reconciliation $reconciliation */
            $reconciliation = $this->books->query(Reconciliation::class, $company)->whereKey($reconciliation->getKey())->lockForUpdate()->firstOrFail();
            $latest = $this->books->query(Reconciliation::class, $company)->where('account_id', $reconciliation->account_id)
                ->where('status', Reconciliation::FINISHED)->orderByDesc('statement_date')->first();
            if ($reconciliation->status !== Reconciliation::FINISHED || $latest?->getKey() !== $reconciliation->getKey()) {
                throw AccountingException::notLatestReconciliation();
            }

            $this->books->query(BankLine::class, $company)->where('reconciliation_id', $reconciliation->getKey())->update(['reconciliation_id' => null]);
            $reconciliation->forceFill([
                'status' => Reconciliation::REOPENED, 'reopened_by' => $actor->getKey(), 'reopened_at' => now(), 'reopen_reason' => $reason,
            ])->save();
            $this->audit->record('accounting.reconciliation_reopened', $reconciliation, new: [
                'account_id' => $reconciliation->account_id, 'statement_date' => $reconciliation->statement_date->toDateString(),
            ], reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $reconciliation;
        });
    }

    /**
     * Statement lines up to a day not inside a finished reconciliation.
     *
     * @return Collection<int, BankLine>
     */
    private function openLines(Organization $company, Account $account, string $date, bool $lock = false): Collection
    {
        return $this->books->query(BankLine::class, $company)->where('account_id', $account->getKey())
            ->whereNull('reconciliation_id')->where('line_date', '<=', $date)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
    }
}
