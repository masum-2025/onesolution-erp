<?php

namespace Modules\Accounting\Http;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Enums\DocumentStatus;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Enums\SettlementStatus;
use Modules\Accounting\Enums\SettlementType;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Allocation;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Opening;
use Modules\Accounting\Models\OpeningLine;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Models\Settlement;
use Modules\Accounting\Models\YearReopenRequest;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Openings;

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
        $request = $this->books->query(YearReopenRequest::class, $company)->where('fiscal_year_id', $year->getKey())->where('status', YearReopenRequest::PENDING)->first();
        $closes = Gate::allows('accounting.close', $company);

        return [
            'id' => $year->getKey(),
            'name' => $year->name,
            'starts_on' => $year->starts_on->toDateString(),
            'ends_on' => $year->ends_on->toDateString(),
            'status' => $year->status,
            'closed_at' => $year->closed_at?->toIso8601String(),
            'closing_journal_id' => $year->closing_journal_id,
            'version' => $year->version,
            'periods' => $this->books->query(Period::class, $company)->where('fiscal_year_id', $year->getKey())->where('is_closing', false)->orderBy('number')->get()
                ->map(fn (Period $period) => $this->period($period))->values()->all(),
            'reopen_request' => $request === null ? null : [
                'id' => $request->getKey(),
                'reason' => $request->reason,
                'requested_by' => $request->requested_by,
                'requested_by_name' => User::query()->whereKey($request->requested_by)->value('name'),
                'requested_at' => $request->created_at?->toIso8601String(),
                'mine' => $request->requested_by === auth()->id(),
            ],
            // What the buttons offer; every action is checked again on the server.
            'can' => [
                'close' => ! $year->isClosed() && $closes,
                'reopen' => $year->isClosed() && $request === null && $closes,
                'approve_reopen' => $request !== null && $request->requested_by !== auth()->id() && $closes,
                'reject_reopen' => $request !== null && $closes,
            ],
        ];
    }

    /**
     * The opening balances with their lines, totals and what the reader may do.
     *
     * @return array<string, mixed>
     */
    public function opening(Opening $opening, Organization $company): array
    {
        $lines = app(Openings::class)->lines($company, $opening);
        $accounts = $this->books->query(Account::class, $company)->whereKey($lines->pluck('account_id')->filter()->unique()->values()->all())->get()->keyBy('id');
        $parties = $this->books->query(Party::class, $company)->whereKey($lines->pluck('party_id')->filter()->unique()->values()->all())->pluck('name', 'id');
        $mine = in_array(auth()->id(), [$opening->created_by, $opening->submitted_by], true);
        $manages = Gate::allows('accounting.manage', $company);
        $approves = Gate::allows('accounting.approve', $company);
        $status = $opening->status;

        return [
            'id' => $opening->getKey(),
            'opening_date' => $opening->opening_date->toDateString(),
            'status' => $status->value,
            'currency' => $this->books->currency($company),
            'journal_id' => $opening->journal_id,
            'created_by' => $opening->created_by,
            'submitted_by' => $opening->submitted_by,
            'reject_reason' => $opening->reject_reason,
            'posted_at' => $opening->posted_at?->toIso8601String(),
            'version' => $opening->version,
            ...Openings::totals($lines),
            'lines' => $lines->map(fn (OpeningLine $line) => [
                'line_no' => $line->line_no,
                'kind' => $line->kind,
                'account_id' => $line->account_id,
                'account_code' => $line->account_id === null ? null : ($accounts[$line->account_id]->code ?? null),
                'account_name' => $line->account_id === null ? null : ($accounts[$line->account_id]->name ?? null),
                'party_id' => $line->party_id,
                'party_name' => $line->party_id === null ? null : ($parties[$line->party_id] ?? null),
                'cost_centre_id' => $line->cost_centre_id,
                'debit_minor' => $line->debit_minor,
                'credit_minor' => $line->credit_minor,
                'amount_minor' => $line->amount(),
                'reference' => $line->reference,
                'issue_date' => $line->issue_date?->toDateString(),
                'due_date' => $line->due_date?->toDateString(),
            ])->values()->all(),
            'can' => [
                'edit' => $status->isEditable() && $manages,
                'submit' => $status->isEditable() && $manages,
                'delete' => $status->isEditable() && $manages,
                'withdraw' => $status === JournalStatus::PendingApproval && $opening->submitted_by === auth()->id(),
                'approve' => $status === JournalStatus::PendingApproval && ! $mine && $approves,
                'reject' => $status === JournalStatus::PendingApproval && ! $mine && $approves,
            ],
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
                // Entries made by invoices, receipts or other modules are undone there.
                'reverse' => $status === JournalStatus::Posted && $journal->source_module === null && $journal->reverses_id === null && $journal->reversed_by_id === null && Gate::allows('accounting.post', $company),
            ],
        ];
    }

    /**
     * @param  array{sales: int, purchases: int}|null  $balances  What the party owes (sales) and is owed (purchases).
     * @return array<string, mixed>
     */
    public function party(Party $party, ?array $balances = null): array
    {
        return [
            'id' => $party->getKey(),
            'name' => $party->name,
            'code' => $party->code,
            'is_customer' => $party->is_customer,
            'is_vendor' => $party->is_vendor,
            'phone' => $party->phone,
            'email' => $party->email,
            'address' => $party->address,
            'tax_number' => $party->tax_number,
            'payment_terms_days' => $party->payment_terms_days,
            'is_active' => $party->is_active,
            'version' => $party->version,
            ...($balances === null ? [] : ['balances' => $balances]),
        ];
    }

    /**
     * @param  array<string, string>  $parties  Party names by id.
     * @return array<string, mixed>
     */
    public function documentItem(Document $document, array $parties = []): array
    {
        return [
            'id' => $document->getKey(),
            'type' => $document->type->value,
            'number' => $document->number,
            'party_id' => $document->party_id,
            'party_name' => $parties[$document->party_id] ?? null,
            'issue_date' => $document->issue_date->toDateString(),
            'due_date' => $document->due_date->toDateString(),
            'reference' => $document->reference,
            'status' => $document->status->value,
            'currency' => $document->currency_code,
            'net_minor' => $document->net_minor,
            'tax_minor' => $document->tax_minor,
            'total_minor' => $document->total_minor,
            'prices_include_tax' => $document->prices_include_tax,
            'is_opening' => $document->is_opening,
            'allocated_minor' => $document->allocated_minor,
            'balance_minor' => $document->balance(),
            'version' => $document->version,
        ];
    }

    /**
     * The whole document: lines, what paid it (or what it paid), and what
     * the reader may do with it now.
     *
     * @return array<string, mixed>
     */
    public function document(Document $document, Organization $company): array
    {
        $party = $this->books->query(Party::class, $company)->find($document->party_id);
        $lines = $this->books->query(DocumentLine::class, $company)->where('document_id', $document->getKey())->orderBy('line_no')->get();
        $accounts = $this->books->query(Account::class, $company)->whereKey($lines->pluck('account_id')->unique()->values()->all())->get()->keyBy('id');
        $column = $document->type->isCredit() ? 'credit_document_id' : 'document_id';
        $allocations = $this->books->query(Allocation::class, $company)->where($column, $document->getKey())->whereNull('voided_at')->orderBy('created_at')->get();
        $mine = in_array(auth()->id(), [$document->created_by, $document->submitted_by], true);
        $writes = Gate::allows($document->type->isSales() ? 'accounting.sell' : 'accounting.buy', $company);
        $approves = Gate::allows('accounting.approve', $company);
        $status = $document->status;

        return [
            ...$this->documentItem($document, $party === null ? [] : [$party->getKey() => $party->name]),
            'notes' => $document->notes,
            'journal_id' => $document->journal_id,
            'reject_reason' => $document->reject_reason,
            'void_reason' => $document->void_reason,
            'posted_at' => $document->posted_at?->toIso8601String(),
            'voided_at' => $document->voided_at?->toIso8601String(),
            'lines' => $lines->map(fn (DocumentLine $line) => [
                'line_no' => $line->line_no,
                'description' => $line->description,
                'quantity' => self::quantityText($line->quantity_milli),
                'unit_price_minor' => $line->unit_price_minor,
                'amount_minor' => $line->amount_minor,
                'account_id' => $line->account_id,
                'account_code' => $accounts[$line->account_id]->code ?? null,
                'account_name' => $accounts[$line->account_id]->name ?? null,
                'cost_centre_id' => $line->cost_centre_id,
                'tax_code_id' => $line->tax_code_id,
                'tax_rate_bp' => $line->tax_rate_bp,
                'tax_minor' => $line->tax_minor,
            ])->values()->all(),
            'allocations' => $allocations->map(fn (Allocation $allocation) => [
                'settlement_id' => $allocation->settlement_id,
                'credit_document_id' => $allocation->credit_document_id,
                'document_id' => $allocation->document_id,
                'amount_minor' => $allocation->amount_minor,
                'allocated_on' => $allocation->allocated_on->toDateString(),
            ])->values()->all(),
            // What the buttons offer; every action is checked again on the server.
            'can' => [
                'edit' => $status->isEditable() && $writes,
                'submit' => $status->isEditable() && $writes,
                'approve' => $status === DocumentStatus::PendingApproval && ! $mine && $approves,
                'reject' => $status === DocumentStatus::PendingApproval && ! $mine && $approves,
                'void' => $status->isPosted() && $document->allocated_minor === 0 && ! $document->is_opening && $approves,
                'apply' => $document->type->isCredit() && $status->isPosted() && $document->balance() > 0 && $writes,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $parties  Party names by id.
     * @return array<string, mixed>
     */
    public function settlementItem(Settlement $settlement, array $parties = []): array
    {
        return [
            'id' => $settlement->getKey(),
            'type' => $settlement->type->value,
            'number' => $settlement->number,
            'party_id' => $settlement->party_id,
            'party_name' => $parties[$settlement->party_id] ?? null,
            'settled_on' => $settlement->settled_on->toDateString(),
            'account_id' => $settlement->account_id,
            'status' => $settlement->status->value,
            'currency' => $settlement->currency_code,
            'amount_minor' => $settlement->amount_minor,
            'allocated_minor' => $settlement->allocated_minor,
            'unallocated_minor' => $settlement->unallocated(),
            'reference' => $settlement->reference,
            'version' => $settlement->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settlement(Settlement $settlement, Organization $company): array
    {
        $party = $this->books->query(Party::class, $company)->find($settlement->party_id);
        $allocations = $this->books->query(Allocation::class, $company)->where('settlement_id', $settlement->getKey())->whereNull('voided_at')->orderBy('created_at')->get();
        $numbers = $this->books->query(Document::class, $company)->whereKey($allocations->pluck('document_id')->all())->pluck('number', 'id');
        $writes = Gate::allows($settlement->type === SettlementType::Receipt ? 'accounting.sell' : 'accounting.buy', $company);
        $approves = Gate::allows('accounting.approve', $company);
        $pending = $settlement->status === SettlementStatus::PendingApproval;
        $mine = auth()->id() === $settlement->created_by;

        return [
            ...$this->settlementItem($settlement, $party === null ? [] : [$party->getKey() => $party->name]),
            'memo' => $settlement->memo,
            'journal_id' => $settlement->journal_id,
            'requested_allocations' => $settlement->requested_allocations,
            'reject_reason' => $settlement->reject_reason,
            'void_reason' => $settlement->void_reason,
            'posted_at' => $settlement->posted_at?->toIso8601String(),
            'allocations' => $allocations->map(fn (Allocation $allocation) => [
                'document_id' => $allocation->document_id,
                'document_number' => $numbers[$allocation->document_id] ?? null,
                'amount_minor' => $allocation->amount_minor,
                'allocated_on' => $allocation->allocated_on->toDateString(),
            ])->values()->all(),
            'can' => [
                'approve' => $pending && ! $mine && $approves,
                'reject' => $pending && ! $mine && $approves,
                'void' => $settlement->status === SettlementStatus::Posted && $approves,
                'allocate' => $settlement->status === SettlementStatus::Posted && $settlement->unallocated() > 0 && $writes,
            ],
        ];
    }

    /** 1500 thousandths -> "1.5"; 2000 -> "2". */
    public static function quantityText(int $milli): string
    {
        $whole = intdiv($milli, 1000);
        $fraction = rtrim(str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : "{$whole}.{$fraction}";
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
