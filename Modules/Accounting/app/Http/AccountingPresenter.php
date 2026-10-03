<?php

namespace Modules\Accounting\Http;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Services\Books;

/**
 * API shapes of the books. Amounts are integer minor units with the
 * journal's currency; the app formats them for the reader's language.
 */
class AccountingPresenter
{
    public function __construct(private Books $books) {}

    /**
     * @return array<string, mixed>
     */
    public function account(Account $account): array
    {
        return [
            'id' => $account->getKey(),
            'code' => $account->code,
            'name' => $account->name,
            'names' => $account->texts('name'),
            'type' => $account->type->value,
            'parent_id' => $account->parent_id,
            'is_group' => $account->is_group,
            'status' => $account->status->value,
            'version' => $account->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function year(FiscalYear $year, Organization $company): array
    {
        return [
            'id' => $year->getKey(),
            'name' => $year->name,
            'starts_on' => $year->starts_on->toDateString(),
            'ends_on' => $year->ends_on->toDateString(),
            'periods' => $this->books->query(Period::class, $company)->where('fiscal_year_id', $year->getKey())->orderBy('number')->get()
                ->map(fn (Period $period) => $this->period($period))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function period(Period $period): array
    {
        return [
            'id' => $period->getKey(),
            'number' => $period->number,
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
            'status' => $period->status->value,
            'closed_at' => $period->closed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listItem(Journal $journal): array
    {
        return [
            'id' => $journal->getKey(),
            'number' => $journal->number,
            'entry_date' => $journal->entry_date->toDateString(),
            'narration' => $journal->narration,
            'status' => $journal->status->value,
            'currency' => $journal->currency_code,
            'total_minor' => $journal->total_minor,
            'source' => $this->source($journal),
            'reverses_id' => $journal->reverses_id,
            'reversed_by_id' => $journal->reversed_by_id,
            'version' => $journal->version,
        ];
    }

    /**
     * The full journal with its lines and what the reader may do with it now.
     *
     * @return array<string, mixed>
     */
    public function journal(Journal $journal, Organization $company): array
    {
        $lines = $this->books->linesOf($company, $journal)->get();
        $accounts = $this->books->query(Account::class, $company)->whereKey($lines->pluck('account_id')->unique()->values()->all())->get()->keyBy('id');
        $mine = in_array(auth()->id(), [$journal->created_by, $journal->submitted_by], true);
        $status = $journal->status;

        return [
            ...$this->listItem($journal),
            'created_by' => $journal->created_by,
            'submitted_by' => $journal->submitted_by,
            'submitted_at' => $journal->submitted_at?->toIso8601String(),
            'approved_by' => $journal->approved_by,
            'rejected_by' => $journal->rejected_by,
            'reject_reason' => $journal->reject_reason,
            'posted_at' => $journal->posted_at?->toIso8601String(),
            'lines' => $lines->map(fn (JournalLine $line) => [
                'line_no' => $line->line_no,
                'account_id' => $line->account_id,
                'account_code' => $accounts[$line->account_id]?->code,
                'account_name' => $accounts[$line->account_id]?->name,
                'cost_centre_id' => $line->cost_centre_id,
                'debit_minor' => $line->debit_minor,
                'credit_minor' => $line->credit_minor,
                'memo' => $line->memo,
            ])->values()->all(),
            // What the buttons offer; every action is checked again on the server.
            'can' => [
                'edit' => $status->isEditable() && Gate::allows('accounting.post', $company),
                'submit' => $status->isEditable() && Gate::allows('accounting.post', $company),
                'withdraw' => $status === JournalStatus::PendingApproval && $journal->submitted_by === auth()->id(),
                'approve' => $status === JournalStatus::PendingApproval && ! $mine && Gate::allows('accounting.approve', $company),
                'reject' => $status === JournalStatus::PendingApproval && ! $mine && Gate::allows('accounting.approve', $company),
                'reverse' => $status === JournalStatus::Posted && $journal->reverses_id === null && $journal->reversed_by_id === null && Gate::allows('accounting.post', $company),
            ],
        ];
    }

    /**
     * @return array{module: string, type: string|null, id: string|null}|null
     */
    private function source(Journal $journal): ?array
    {
        return $journal->source_module === null ? null : [
            'module' => $journal->source_module, 'type' => $journal->source_type, 'id' => $journal->source_id,
        ];
    }
}
