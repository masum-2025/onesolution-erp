<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\PeriodStatus;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Balance;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Models\PostingAccount;
use Modules\Accounting\Models\YearReopenRequest;

/**
 * Closing a fiscal year and reopening it.
 *
 * Closing: years close in order, once every month of the year is closed
 * (rule accounting.year_close_requires_all_periods; when off, the months
 * still open are closed now). One entry, dated the year's last day, moves
 * each income and expense balance (per branch or department) into retained
 * earnings (posting key accounting.retained_earnings). It sits in the year's
 * closing period, which profit and loss reports leave out. The next year is
 * added when it does not exist yet.
 *
 * Reopening: someone asks with a reason, another person approves (rule
 * accounting.year_reopen_needs_second_person; off for one-person books).
 * The closing entry is reversed in the same closing period; the months stay
 * closed until someone reopens the one they need. Only the latest closed
 * year reopens.
 */
class YearEnd
{
    /** Journals of the year-end closing carry this source type (module accounting). */
    public const SOURCE_TYPE = 'year_close';

    /** The closing period comes after the twelve months. */
    public const CLOSING_PERIOD = FiscalCalendar::PERIODS + 1;

    public function __construct(
        private Books $books,
        private Journals $journals,
        private FiscalCalendar $calendar,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    public function close(Organization $company, FiscalYear $year, int $baseVersion, User $actor): FiscalYear
    {
        return $this->books->transaction($company, function () use ($company, $year, $baseVersion, $actor) {
            $year = $this->lockYear($company, $year, $baseVersion);
            if ($year->isClosed()) {
                throw AccountingException::yearAlreadyClosed();
            }
            if ($this->books->query(FiscalYear::class, $company)->where('starts_on', '<', $year->starts_on->toDateString())->where('status', FiscalYear::OPEN)->exists()) {
                throw AccountingException::earlierYearOpen();
            }

            $open = $this->books->query(Period::class, $company)->where('fiscal_year_id', $year->getKey())
                ->where('is_closing', false)->where('status', PeriodStatus::Open->value)->orderBy('number')->get();
            if ($open->isNotEmpty()) {
                if ((bool) $this->rules->get('accounting.year_close_requires_all_periods', $this->contexts->forOrganization($company))) {
                    throw AccountingException::periodsStillOpen($open->count());
                }
                // Closing a month refuses while entries in it wait for approval.
                $open->each(fn (Period $period) => $this->calendar->close($company, $period, $actor));
            }

            $retained = $this->books->query(PostingAccount::class, $company)->where('posting_key', 'accounting.retained_earnings')->value('account_id')
                ?? throw AccountingException::postingAccountMissing('accounting.retained_earnings');

            $closingPeriod = $this->closingPeriod($company, $year, $actor);
            [$lines, $profit] = $this->closingLines($company, $year, (string) $retained);

            $journal = null;
            if ($lines !== []) {
                $journal = $this->journals->draft($company, [
                    'entry_date' => $year->ends_on->toDateString(),
                    'narration' => mb_substr(__('accounting::accounting.year_close_narration', ['year' => $year->name]), 0, 500),
                    'lines' => $lines,
                ], $actor, ['module' => 'accounting', 'type' => self::SOURCE_TYPE, 'id' => $year->getKey()]);
                $journal = $this->journals->postApproved($company, $journal, $actor, $actor, into: $closingPeriod);
            }

            $year->forceFill([
                'status' => FiscalYear::CLOSED,
                'closed_by' => $actor->getKey(),
                'closed_at' => now(),
                'closing_journal_id' => $journal?->getKey(),
                'version' => $year->version + 1,
            ])->save();

            // Work goes on in the next year.
            if (! $this->books->query(FiscalYear::class, $company)->where('starts_on', '>', $year->starts_on->toDateString())->exists()) {
                $this->calendar->addYear($company, null, $actor);
            }

            $this->audit->record('accounting.year_closed', $year, new: [
                'name' => $year->name, 'net_profit_minor' => $profit, 'journal' => $journal?->number,
            ], actor: $actor, organizationId: $company->getKey());

            return $year;
        });
    }

    /**
     * Ask to reopen a closed year. Without the second-person rule the year
     * reopens at once (the request is approved by the same person).
     */
    public function requestReopen(Organization $company, FiscalYear $year, string $reason, User $actor): YearReopenRequest
    {
        return $this->books->transaction($company, function () use ($company, $year, $reason, $actor) {
            $year = $this->lockYear($company, $year, null);
            $this->assertReopenable($company, $year);
            if ($this->books->query(YearReopenRequest::class, $company)->where('fiscal_year_id', $year->getKey())->where('status', YearReopenRequest::PENDING)->exists()) {
                throw AccountingException::reopenAlreadyAsked();
            }

            $request = new YearReopenRequest;
            $request->fill([
                'organization_id' => $company->getKey(),
                'fiscal_year_id' => $year->getKey(),
                'reason' => $reason,
                'status' => YearReopenRequest::PENDING,
                'requested_by' => $actor->getKey(),
            ])->save();
            $this->audit->record('accounting.year_reopen_requested', $year, new: ['name' => $year->name], reason: $reason, actor: $actor, organizationId: $company->getKey());

            if (! (bool) $this->rules->get('accounting.year_reopen_needs_second_person', $this->contexts->forOrganization($company))) {
                return $this->decide($company, $request, $year, YearReopenRequest::APPROVED, null, $actor);
            }

            return $request;
        });
    }

    /** Another person agrees: the closing entry is reversed and the year is open again. */
    public function approveReopen(Organization $company, YearReopenRequest $request, User $actor): YearReopenRequest
    {
        return $this->books->transaction($company, function () use ($company, $request, $actor) {
            $request = $this->pendingRequest($company, $request);
            if ($request->requested_by === $actor->getKey()) {
                throw AccountingException::ownReopenRequest();
            }
            $year = $this->lockYear($company, $this->books->query(FiscalYear::class, $company)->findOrFail($request->fiscal_year_id), null);
            $this->assertReopenable($company, $year);

            return $this->decide($company, $request, $year, YearReopenRequest::APPROVED, null, $actor);
        });
    }

    /** Turn a request down (the person who asked may also take it back this way). */
    public function rejectReopen(Organization $company, YearReopenRequest $request, ?string $note, User $actor): YearReopenRequest
    {
        return $this->books->transaction($company, function () use ($company, $request, $note, $actor) {
            $request = $this->pendingRequest($company, $request);
            $year = $this->books->query(FiscalYear::class, $company)->findOrFail($request->fiscal_year_id);

            return $this->decide($company, $request, $year, YearReopenRequest::REJECTED, $note, $actor);
        });
    }

    private function decide(Organization $company, YearReopenRequest $request, FiscalYear $year, string $status, ?string $note, User $actor): YearReopenRequest
    {
        if ($status === YearReopenRequest::APPROVED) {
            $this->reopen($company, $year, $request->reason, $actor);
        }

        $request->forceFill(['status' => $status, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note])->save();
        if ($status === YearReopenRequest::REJECTED) {
            $this->audit->record('accounting.year_reopen_rejected', $year, new: ['name' => $year->name], reason: $note, actor: $actor, organizationId: $company->getKey());
        }

        return $request;
    }

    private function reopen(Organization $company, FiscalYear $year, string $reason, User $actor): void
    {
        $reversal = null;
        if ($year->closing_journal_id !== null) {
            /** @var Journal $closing */
            $closing = $this->books->query(Journal::class, $company)->findOrFail($year->closing_journal_id);
            $reversal = $this->journals->draft($company, [
                'entry_date' => $year->ends_on->toDateString(),
                'narration' => mb_substr(__('accounting::accounting.reversal_narration', ['number' => $closing->number, 'reason' => $reason]), 0, 500),
                'reverses_id' => $closing->getKey(),
                'lines' => $this->books->linesOf($company, $closing)->get()->map(fn (JournalLine $line) => [
                    'account_id' => $line->account_id,
                    'cost_centre_id' => $line->cost_centre_id,
                    'debit_minor' => $line->credit_minor,
                    'credit_minor' => $line->debit_minor,
                    'memo' => $line->memo,
                ])->all(),
            ], $actor, ['module' => 'accounting', 'type' => self::SOURCE_TYPE, 'id' => $year->getKey()]);
            $reversal = $this->journals->postApproved($company, $reversal, $actor, $actor, into: $this->closingPeriod($company, $year, $actor));
        }

        $year->forceFill(['status' => FiscalYear::OPEN, 'closed_by' => null, 'closed_at' => null, 'closing_journal_id' => null, 'version' => $year->version + 1])->save();
        $this->audit->record('accounting.year_reopened', $year, new: ['name' => $year->name, 'reversal' => $reversal?->number], reason: $reason, actor: $actor, organizationId: $company->getKey());
    }

    /** Closed, and no later year closed on top of it. */
    private function assertReopenable(Organization $company, FiscalYear $year): void
    {
        if (! $year->isClosed()) {
            throw AccountingException::yearNotClosed();
        }
        if ($this->books->query(FiscalYear::class, $company)->where('starts_on', '>', $year->starts_on->toDateString())->where('status', FiscalYear::CLOSED)->exists()) {
            throw AccountingException::laterYearClosed();
        }
    }

    /**
     * The entry that empties income and expense accounts into retained
     * earnings: each account's balance per cost centre the other way round,
     * and per cost centre the difference to retained earnings.
     *
     * @return array{0: list<array{account_id: string, cost_centre_id: string, debit_minor: int, credit_minor: int, memo: string|null}>, 1: int}
     */
    private function closingLines(Organization $company, FiscalYear $year, string $retained): array
    {
        $accounts = $this->books->query(Account::class, $company)->whereIn('type', [AccountType::Income->value, AccountType::Expense->value])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $periods = $this->books->query(Period::class, $company)->where('fiscal_year_id', $year->getKey())->where('is_closing', false)->pluck('id')->all();
        if ($accounts === [] || $periods === []) {
            return [[], 0];
        }

        $net = [];
        $this->books->query(Balance::class, $company)->whereIn('period_id', $periods)->whereIn('account_id', $accounts)
            ->orderBy('account_id')->orderBy('cost_centre_id')
            ->get(['account_id', 'cost_centre_id', 'debit_minor', 'credit_minor'])
            ->each(function (Balance $row) use (&$net) {
                $key = $row->account_id.'|'.$row->cost_centre_id;
                $net[$key] = ($net[$key] ?? 0) + $row->debit_minor - $row->credit_minor;
            });

        $lines = [];
        $byCentre = [];
        foreach ($net as $key => $amount) {
            if ($amount === 0) {
                continue;
            }
            [$account, $centre] = explode('|', $key);
            $lines[] = ['account_id' => $account, 'cost_centre_id' => $centre, 'debit_minor' => max(-$amount, 0), 'credit_minor' => max($amount, 0), 'memo' => null];
            $byCentre[$centre] = ($byCentre[$centre] ?? 0) + $amount;
        }

        // Profit (more credit than debit) is credited to retained earnings; a loss debited.
        $profit = 0;
        foreach ($byCentre as $centre => $amount) {
            if ($amount === 0) {
                continue;
            }
            $lines[] = ['account_id' => $retained, 'cost_centre_id' => $centre, 'debit_minor' => max($amount, 0), 'credit_minor' => max(-$amount, 0), 'memo' => null];
            $profit -= $amount;
        }

        return [$lines, $profit];
    }

    /** The year's closing period (made the first time the year closes), locked. */
    private function closingPeriod(Organization $company, FiscalYear $year, User $actor): Period
    {
        $find = fn () => $this->books->query(Period::class, $company)->where('fiscal_year_id', $year->getKey())->where('is_closing', true)->lockForUpdate()->first();
        if ($period = $find()) {
            return $period;
        }

        $period = new Period;
        $period->fill([
            'organization_id' => $company->getKey(),
            'fiscal_year_id' => $year->getKey(),
            'number' => self::CLOSING_PERIOD,
            'starts_on' => $year->ends_on,
            'ends_on' => $year->ends_on,
            'status' => PeriodStatus::Closed,
            'closed_by' => $actor->getKey(),
            'closed_at' => now(),
        ]);
        $period->forceFill(['is_closing' => true])->save();

        return $find();
    }

    private function lockYear(Organization $company, FiscalYear $year, ?int $baseVersion): FiscalYear
    {
        /** @var FiscalYear $fresh */
        $fresh = $this->books->query(FiscalYear::class, $company)->whereKey($year->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw AccountingException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }

        return $fresh;
    }

    private function pendingRequest(Organization $company, YearReopenRequest $request): YearReopenRequest
    {
        /** @var YearReopenRequest $fresh */
        $fresh = $this->books->query(YearReopenRequest::class, $company)->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->status !== YearReopenRequest::PENDING) {
            throw AccountingException::reopenNotPending();
        }

        return $fresh;
    }
}
