<?php

namespace Modules\Accounting\Services;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Balance;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Period;

/**
 * Financial reports from posted journals only. Whole periods are read from
 * the period totals (acc_balances); the days of a period cut by the date
 * range come from the lines themselves. Sums are made here, not in SQL, so
 * the same code runs on MySQL and PostgreSQL. A cost centre filter includes
 * the units below it.
 *
 * Closing a fiscal year moves its income and expenses into retained earnings
 * with an entry in the year's closing period. Profit and loss leaves that
 * entry out (it would cancel the year's profit); balances include it. The
 * balance sheet shows the profit of years not yet closed as "earnings to date".
 */
class Reports
{
    /** Most lines one ledger page shows; a longer range is refused with advice to narrow it. */
    public const MAX_LEDGER_LINES = 5000;

    public function __construct(private Books $books) {}

    /**
     * @param  list<string>|null  $costCentres
     * @return array<string, mixed>
     */
    public function trialBalance(Organization $company, string $asOf, ?array $costCentres): array
    {
        $totals = $this->totals($company, null, $asOf, $costCentres);
        $rows = [];
        $sum = ['debit' => 0, 'credit' => 0];
        foreach ($this->accounts($company) as $account) {
            $net = ($totals[$account->getKey()]['debit'] ?? 0) - ($totals[$account->getKey()]['credit'] ?? 0);
            if ($net === 0) {
                continue;
            }
            $row = [...$this->accountRow($account), 'debit_minor' => max($net, 0), 'credit_minor' => max(-$net, 0)];
            $sum['debit'] += $row['debit_minor'];
            $sum['credit'] += $row['credit_minor'];
            $rows[] = $row;
        }

        return [
            'as_of' => $asOf,
            'currency' => $this->books->currency($company),
            'rows' => $rows,
            'total_debit_minor' => $sum['debit'],
            'total_credit_minor' => $sum['credit'],
        ];
    }

    /**
     * One account's entries between two dates, with the balance before, after
     * each line (in the account's own direction) and at the end.
     *
     * @param  list<string>|null  $costCentres
     * @return array<string, mixed>
     */
    public function ledger(Organization $company, Account $account, string $from, string $to, ?array $costCentres): array
    {
        $before = CarbonImmutable::parse($from, 'UTC')->subDay()->toDateString();
        $opening = $this->totals($company, null, $before, $costCentres, [$account->getKey()])[$account->getKey()] ?? ['debit' => 0, 'credit' => 0];
        $balance = $account->type->balance($opening['debit'], $opening['credit']);

        // A ledger lists closing entries too: they are real lines of the account.
        $lines = $this->postedLines($company, $from, $to, $costCentres, withClosing: true)->where('acc_journal_lines.account_id', $account->getKey());
        if ((clone $lines)->count() > self::MAX_LEDGER_LINES) {
            throw AccountingException::rangeTooLarge(self::MAX_LEDGER_LINES);
        }

        $rows = [];
        foreach ($lines->orderBy('acc_journals.entry_date')->orderBy('acc_journals.posted_at')->orderBy('acc_journals.id')->orderBy('acc_journal_lines.line_no')
            ->select(['acc_journal_lines.*', 'acc_journals.entry_date', 'acc_journals.number', 'acc_journals.narration'])->get() as $line) {
            $balance += $account->type->balance($line->debit_minor, $line->credit_minor);
            $rows[] = [
                'journal_id' => $line->journal_id,
                'number' => $line->number,
                'entry_date' => CarbonImmutable::parse($line->entry_date)->toDateString(),
                'narration' => $line->narration,
                'memo' => $line->memo,
                'cost_centre_id' => $line->cost_centre_id,
                'debit_minor' => $line->debit_minor,
                'credit_minor' => $line->credit_minor,
                'balance_minor' => $balance,
            ];
        }

        return [
            'account' => $this->accountRow($account),
            'from' => $from,
            'to' => $to,
            'currency' => $this->books->currency($company),
            'opening_minor' => $account->type->balance($opening['debit'], $opening['credit']),
            'rows' => $rows,
            'closing_minor' => $balance,
        ];
    }

    /**
     * @param  list<string>|null  $costCentres
     * @return array<string, mixed>
     */
    public function profitAndLoss(Organization $company, string $from, string $to, ?array $costCentres): array
    {
        $sections = $this->sections($company, $this->totals($company, $from, $to, $costCentres, withClosing: false), [AccountType::Income, AccountType::Expense]);

        return [
            'from' => $from,
            'to' => $to,
            'currency' => $this->books->currency($company),
            'income' => $sections['income'],
            'expense' => $sections['expense'],
            'net_profit_minor' => $sections['income']['total_minor'] - $sections['expense']['total_minor'],
        ];
    }

    /**
     * @param  list<string>|null  $costCentres
     * @return array<string, mixed>
     */
    public function balanceSheet(Organization $company, string $asOf, ?array $costCentres): array
    {
        $sections = $this->sections($company, $this->totals($company, null, $asOf, $costCentres), AccountType::cases());
        $earnings = $sections['income']['total_minor'] - $sections['expense']['total_minor'];

        return [
            'as_of' => $asOf,
            'currency' => $this->books->currency($company),
            'assets' => $sections['asset'],
            'liabilities' => $sections['liability'],
            'equity' => $sections['equity'],
            'earnings_to_date_minor' => $earnings,
            'total_liabilities_and_equity_minor' => $sections['liability']['total_minor'] + $sections['equity']['total_minor'] + $earnings,
        ];
    }

    /**
     * Posted debit and credit per account between two dates (from null = since
     * the beginning). $withClosing false leaves out year-end closing entries
     * (profit for a range); true gives balances.
     *
     * @param  list<string>|null  $costCentres
     * @param  list<string>|null  $accountIds
     * @return array<string, array{debit: int, credit: int}>
     */
    public function totals(Organization $company, ?string $from, string $to, ?array $costCentres, ?array $accountIds = null, bool $withClosing = true): array
    {
        $periods = $this->books->query(Period::class, $company)
            ->when(! $withClosing, fn ($query) => $query->where('is_closing', false))
            ->where('starts_on', '<=', $to)
            ->when($from !== null, fn ($query) => $query->where('ends_on', '>=', $from))
            ->get();

        $whole = [];
        $partial = [];
        foreach ($periods as $period) {
            $start = $period->starts_on->toDateString();
            $end = $period->ends_on->toDateString();
            if (($from === null || $start >= $from) && $end <= $to) {
                $whole[] = $period->getKey();
            } else {
                $partial[] = [max($from ?? $start, $start), min($to, $end)];
            }
        }

        $totals = [];
        $add = function (string $account, int $debit, int $credit) use (&$totals) {
            $totals[$account] ??= ['debit' => 0, 'credit' => 0];
            $totals[$account]['debit'] += $debit;
            $totals[$account]['credit'] += $credit;
        };

        if ($whole !== []) {
            $this->books->query(Balance::class, $company)
                ->whereIn('period_id', $whole)
                ->when($costCentres !== null, fn ($query) => $query->whereIn('cost_centre_id', $costCentres))
                ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds))
                ->get(['account_id', 'debit_minor', 'credit_minor'])
                ->each(fn (Balance $row) => $add($row->account_id, $row->debit_minor, $row->credit_minor));
        }

        foreach ($partial as [$start, $end]) {
            foreach ($this->postedLines($company, $start, $end, $costCentres)
                ->when($accountIds !== null, fn ($query) => $query->whereIn('acc_journal_lines.account_id', $accountIds))
                ->cursor() as $line) {
                $add($line->account_id, $line->debit_minor, $line->credit_minor);
            }
        }

        return $totals;
    }

    /**
     * Lines of posted journals dated between two days. Closing entries sit in
     * the closing period (read whole from its totals), so they are left out
     * here unless a ledger lists them.
     *
     * @param  list<string>|null  $costCentres
     * @return Builder<JournalLine>
     */
    private function postedLines(Organization $company, string $from, string $to, ?array $costCentres, bool $withClosing = false): Builder
    {
        return $this->books->query(JournalLine::class, $company)
            ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->where('acc_journals.status', JournalStatus::Posted->value)
            ->when(! $withClosing, fn ($query) => $query->where(fn ($query) => $query->whereNull('acc_journals.source_type')->orWhere('acc_journals.source_type', '!=', YearEnd::SOURCE_TYPE)))
            ->whereBetween('acc_journals.entry_date', [$from, $to])
            ->when($costCentres !== null, fn ($query) => $query->whereIn('acc_journal_lines.cost_centre_id', $costCentres))
            ->select(['acc_journal_lines.account_id', 'acc_journal_lines.debit_minor', 'acc_journal_lines.credit_minor']);
    }

    /**
     * Accounts with an amount, by type, each total in the type's own direction.
     *
     * @param  array<string, array{debit: int, credit: int}>  $totals
     * @param  list<AccountType>  $types
     * @return array<string, array{rows: list<array<string, mixed>>, total_minor: int}>
     */
    private function sections(Organization $company, array $totals, array $types): array
    {
        $sections = [];
        foreach ($types as $type) {
            $sections[$type->value] = ['rows' => [], 'total_minor' => 0];
        }

        foreach ($this->accounts($company) as $account) {
            if (! isset($sections[$account->type->value], $totals[$account->getKey()])) {
                continue;
            }
            $amount = $account->type->balance($totals[$account->getKey()]['debit'], $totals[$account->getKey()]['credit']);
            if ($amount === 0) {
                continue;
            }
            $sections[$account->type->value]['rows'][] = [...$this->accountRow($account), 'amount_minor' => $amount];
            $sections[$account->type->value]['total_minor'] += $amount;
        }

        return $sections;
    }

    /**
     * @return Collection<int, Account>
     */
    private function accounts(Organization $company): Collection
    {
        return $this->books->query(Account::class, $company)->where('is_group', false)->orderBy('code')->get();
    }

    /**
     * @return array{account_id: string, code: string, name: string, type: string, parent_id: string|null}
     */
    private function accountRow(Account $account): array
    {
        return [
            'account_id' => $account->getKey(),
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'parent_id' => $account->parent_id,
        ];
    }
}
