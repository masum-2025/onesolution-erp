<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Events\JournalPosted;
use Modules\Accounting\Models\Balance;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\Journal;

/**
 * Puts a checked, balanced journal into the books: into its open period,
 * with the next number of the fiscal year, adding its lines to the period
 * totals. Locks the period row first, so postings of one period run one
 * after another and nobody closes the period halfway. Call inside a
 * transaction (Journals does).
 */
class Posting
{
    public function __construct(
        private Books $books,
        private FiscalCalendar $calendar,
        private JournalNumbers $numbers,
        private AuditLogger $audit,
    ) {}

    public function post(Organization $company, Journal $journal, ?User $approver, ?User $actor): Journal
    {
        $period = $this->calendar->openPeriodFor($company, $journal->entry_date);
        /** @var FiscalYear $year */
        $year = $this->books->query(FiscalYear::class, $company)->findOrFail($period->fiscal_year_id);

        $journal->forceFill([
            'status' => JournalStatus::Posted,
            'number' => $this->numbers->next($company, $year),
            'posted_at' => now(),
            'approved_by' => $approver?->getKey(),
            'version' => $journal->version + 1,
        ])->save();

        $totals = [];
        foreach ($this->books->linesOf($company, $journal)->get() as $line) {
            $key = $line->account_id.'|'.$line->cost_centre_id;
            $totals[$key] ??= ['account_id' => $line->account_id, 'cost_centre_id' => $line->cost_centre_id, 'debit' => 0, 'credit' => 0];
            $totals[$key]['debit'] += $line->debit_minor;
            $totals[$key]['credit'] += $line->credit_minor;
        }
        foreach ($totals as $total) {
            $balance = $this->books->query(Balance::class, $company)->where([
                'account_id' => $total['account_id'], 'period_id' => $period->getKey(), 'cost_centre_id' => $total['cost_centre_id'],
            ])->first() ?? new Balance([
                'organization_id' => $company->getKey(), 'account_id' => $total['account_id'], 'period_id' => $period->getKey(),
                'cost_centre_id' => $total['cost_centre_id'], 'debit_minor' => 0, 'credit_minor' => 0,
            ]);
            $balance->debit_minor += $total['debit'];
            $balance->credit_minor += $total['credit'];
            $balance->save();
        }

        if ($journal->reverses_id !== null) {
            $original = $this->books->query(Journal::class, $company)->findOrFail($journal->reverses_id);
            $original->reversed_by_id = $journal->getKey();
            $original->save();
        }

        $this->audit->record('accounting.journal_posted', $journal, new: [
            'number' => $journal->number, 'entry_date' => $journal->entry_date->toDateString(),
            'total_minor' => $journal->total_minor, 'currency' => $journal->currency_code,
            'source' => $journal->source_module === null ? null : "{$journal->source_module}.{$journal->source_type}",
            'reverses_id' => $journal->reverses_id, 'approved_by' => $approver?->getKey(),
        ], actor: $actor, organizationId: $company->getKey());

        JournalPosted::dispatch($journal->getKey(), $company->getKey(), $journal->entry_date->toDateString(), $journal->source_module, $journal->source_type, $journal->source_id);

        return $journal;
    }
}
