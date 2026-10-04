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
use Modules\Accounting\Enums\DocumentStatus;
use Modules\Accounting\Enums\DocumentType;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Accounting\Models\Opening;
use Modules\Accounting\Models\OpeningLine;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Models\PostingAccount;

/**
 * The balances a company brings from its old books, entered once:
 *
 *   draft -> submit -> posted
 *                   -> pending_approval -> approve -> posted
 *                                       -> reject  -> rejected (edit, submit again)
 *                                       -> withdraw -> draft
 *
 * Account lines carry a debit or a credit. What customers still owe and
 * what is still owed to vendors is entered per old invoice or bill (with its
 * dates), never straight onto the receivable or payable account, so aging,
 * statements and receipts work from day one: posting makes each one an
 * opening invoice or bill. Whatever does not balance goes to the opening
 * balance account (posting key accounting.opening_balance), shown before
 * sending. One journal, dated the opening date, posts it all; approval above
 * the company's amount, never by the person who wrote it. Once posted it
 * never changes: corrections are ordinary entries and credit notes.
 */
class Openings
{
    /** Journals of the opening carry this source type (module accounting). */
    public const SOURCE_TYPE = 'opening';

    public function __construct(
        private Books $books,
        private Journals $journals,
        private Documents $documents,
        private FiscalCalendar $calendar,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    public function current(Organization $company): ?Opening
    {
        return $this->books->query(Opening::class, $company)->first();
    }

    /** @return Collection<int, OpeningLine> */
    public function lines(Organization $company, Opening $opening): Collection
    {
        return $this->books->query(OpeningLine::class, $company)->where('opening_id', $opening->getKey())->orderBy('line_no')->get();
    }

    /**
     * Write the draft, or replace a draft (or rejected) one as a whole.
     *
     * @param  array{opening_date: string, lines: list<array<string, mixed>>}  $data
     */
    public function save(Organization $company, array $data, ?int $baseVersion, User $actor): Opening
    {
        $this->books->assertSetUp($company);
        $date = CarbonImmutable::parse($data['opening_date'], 'UTC');
        if ($this->calendar->periodFor($company, $date) === null) {
            throw ValidationException::withMessages(['opening_date' => __('accounting::accounting.validation.opening_date')]);
        }
        $lines = $this->checkLines($company, $data['lines'], $date);

        return $this->books->transaction($company, function () use ($company, $date, $lines, $baseVersion, $actor) {
            $opening = $this->books->query(Opening::class, $company)->lockForUpdate()->first();
            if ($opening !== null) {
                $this->assertVersion($opening, $baseVersion);
                $this->assertEditable($opening);
                $old = $this->auditValues($company, $opening);
                $this->books->query(OpeningLine::class, $company)->where('opening_id', $opening->getKey())->delete();
                $opening->forceFill(['opening_date' => $date->toDateString(), 'status' => JournalStatus::Draft, 'version' => $opening->version + 1])->save();
            } else {
                $old = [];
                $opening = new Opening;
                $opening->fill([
                    'organization_id' => $company->getKey(), 'opening_date' => $date->toDateString(),
                    'status' => JournalStatus::Draft, 'created_by' => $actor->getKey(), 'version' => 1,
                ])->save();
            }

            foreach ($lines as $index => $line) {
                $row = new OpeningLine;
                $row->fill([...$line, 'organization_id' => $company->getKey(), 'opening_id' => $opening->getKey(), 'line_no' => $index + 1])->save();
            }

            $this->audit->record('accounting.opening_saved', $opening, old: $old, new: $this->auditValues($company, $opening), actor: $actor, organizationId: $company->getKey());

            return $opening;
        });
    }

    /** Throw a draft away (nothing was posted). */
    public function delete(Organization $company, int $baseVersion, User $actor): void
    {
        $this->books->transaction($company, function () use ($company, $baseVersion, $actor) {
            $opening = $this->lock($company, $baseVersion);
            $this->assertEditable($opening);

            $this->audit->record('accounting.opening_deleted', $opening, old: $this->auditValues($company, $opening), actor: $actor, organizationId: $company->getKey());
            $this->books->query(OpeningLine::class, $company)->where('opening_id', $opening->getKey())->delete();
            $opening->delete();
        });
    }

    /** Posted at once, or kept for a second person when the amount needs approval. */
    public function submit(Organization $company, int $baseVersion, User $actor): Opening
    {
        return $this->books->transaction($company, function () use ($company, $baseVersion, $actor) {
            $opening = $this->lock($company, $baseVersion);
            $this->assertEditable($opening);
            // Fail early: the date's month is open and every account the entry needs is mapped.
            $this->calendar->openPeriodFor($company, $opening->opening_date);
            $lines = $this->journalLines($company, $opening);
            $total = array_sum(array_column($lines, 'debit_minor'));

            if ($this->journals->amountNeedsApproval($company, $total, $this->books->currency($company))) {
                $opening->forceFill([
                    'status' => JournalStatus::PendingApproval, 'submitted_by' => $actor->getKey(), 'submitted_at' => now(),
                    'reject_reason' => null, 'version' => $opening->version + 1,
                ])->save();
                $this->audit->record('accounting.opening_submitted', $opening, new: $this->auditValues($company, $opening), actor: $actor, organizationId: $company->getKey());

                return $opening;
            }

            $opening->forceFill(['submitted_by' => $actor->getKey(), 'submitted_at' => now()]);

            return $this->post($company, $opening, null, $actor);
        });
    }

    public function approve(Organization $company, int $baseVersion, User $actor): Opening
    {
        return $this->books->transaction($company, function () use ($company, $baseVersion, $actor) {
            return $this->post($company, $this->pending($company, $baseVersion, $actor), $actor, $actor);
        });
    }

    public function reject(Organization $company, int $baseVersion, string $reason, User $actor): Opening
    {
        return $this->books->transaction($company, function () use ($company, $baseVersion, $reason, $actor) {
            $opening = $this->pending($company, $baseVersion, $actor);
            $opening->forceFill(['status' => JournalStatus::Rejected, 'reject_reason' => $reason, 'version' => $opening->version + 1])->save();
            $this->audit->record('accounting.opening_rejected', $opening, new: $this->auditValues($company, $opening), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $opening;
        });
    }

    /** The person who sent it takes it back to change it. */
    public function withdraw(Organization $company, int $baseVersion, User $actor): Opening
    {
        return $this->books->transaction($company, function () use ($company, $baseVersion, $actor) {
            $opening = $this->lock($company, $baseVersion);
            if ($opening->status !== JournalStatus::PendingApproval) {
                throw AccountingException::notPending();
            }
            if ($opening->submitted_by !== $actor->getKey()) {
                throw AccountingException::notSubmitter();
            }

            $opening->forceFill(['status' => JournalStatus::Draft, 'submitted_by' => null, 'submitted_at' => null, 'version' => $opening->version + 1])->save();
            $this->audit->record('accounting.opening_withdrawn', $opening, new: $this->auditValues($company, $opening), actor: $actor, organizationId: $company->getKey());

            return $opening;
        });
    }

    /**
     * Debits, credits and what does not balance (debit minus credit; the
     * opening balance account takes it the other way).
     *
     * @param  iterable<OpeningLine>  $lines
     * @return array{debit_minor: int, credit_minor: int, difference_minor: int}
     */
    public static function totals(iterable $lines): array
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $debit += $line->debit_minor;
            $credit += $line->credit_minor;
        }

        return ['debit_minor' => $debit, 'credit_minor' => $credit, 'difference_minor' => $debit - $credit];
    }

    /**
     * Lines as written. Accounts: usable, not receivable, payable or opening
     * balance (those are worked out), debit or credit. Customers and vendors:
     * active parties of that kind, an amount owed, the old invoice's date on
     * or before the opening date and a due date not before it (default: the
     * party's terms).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function checkLines(Organization $company, array $lines, CarbonImmutable $date): array
    {
        $accounts = $this->books->query(Account::class, $company)->whereKey(array_values(array_filter(array_column($lines, 'account_id'), 'is_string')))->get()->keyBy('id');
        $parties = $this->books->query(Party::class, $company)->whereKey(array_values(array_filter(array_column($lines, 'party_id'), 'is_string')))->get()->keyBy('id');
        $worked = $this->books->query(PostingAccount::class, $company)
            ->whereIn('posting_key', ['accounting.receivable', 'accounting.payable', 'accounting.opening_balance'])->pluck('account_id')->all();
        $needsUnit = (bool) $this->rules->get('accounting.require_cost_centre', $this->contexts->forOrganization($company));

        $errors = [];
        $checked = [];
        foreach (array_values($lines) as $index => $line) {
            $kind = (string) $line['kind'];
            $costCentre = $this->books->costCentre($company, $line['cost_centre_id'] ?? null);
            if ($costCentre === null) {
                $errors["lines.{$index}.cost_centre_id"] = __('accounting::accounting.validation.cost_centre_outside');
            } elseif ($needsUnit && $costCentre === $company->getKey()) {
                $errors["lines.{$index}.cost_centre_id"] = __('accounting::accounting.validation.cost_centre_required');
            }
            $row = ['kind' => $kind, 'account_id' => null, 'party_id' => null, 'cost_centre_id' => (string) $costCentre,
                'debit_minor' => 0, 'credit_minor' => 0, 'reference' => null, 'issue_date' => null, 'due_date' => null];

            if ($kind === 'account') {
                $account = $accounts[$line['account_id'] ?? ''] ?? null;
                if ($account === null || ! $account->isPostable()) {
                    $errors["lines.{$index}.account_id"] = __('accounting::accounting.validation.account_not_postable');
                } elseif (in_array($account->getKey(), $worked, true)) {
                    $errors["lines.{$index}.account_id"] = __('accounting::accounting.validation.opening_worked_account');
                }
                $debit = (int) ($line['debit_minor'] ?? 0);
                $credit = (int) ($line['credit_minor'] ?? 0);
                if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                    $errors["lines.{$index}.debit_minor"] = __('accounting::accounting.validation.one_side');
                }
                $checked[] = [...$row, 'account_id' => (string) ($line['account_id'] ?? ''), 'debit_minor' => $debit, 'credit_minor' => $credit];

                continue;
            }

            $party = $parties[$line['party_id'] ?? ''] ?? null;
            if ($party === null || ! $party->is_active || ! ($kind === 'customer' ? $party->is_customer : $party->is_vendor)) {
                $errors["lines.{$index}.party_id"] = __('accounting::accounting.validation.party_'.$kind);
            }
            $amount = (int) ($line['amount_minor'] ?? 0);
            if ($amount <= 0) {
                $errors["lines.{$index}.amount_minor"] = __('accounting::accounting.validation.positive_amount');
            }
            $issued = CarbonImmutable::parse((string) $line['issue_date'], 'UTC');
            if ($issued->greaterThan($date)) {
                $errors["lines.{$index}.issue_date"] = __('accounting::accounting.validation.opening_issue_date');
            }
            $due = isset($line['due_date']) ? CarbonImmutable::parse((string) $line['due_date'], 'UTC') : null;
            if ($due !== null && $due->lessThan($issued)) {
                $errors["lines.{$index}.due_date"] = __('accounting::accounting.validation.due_before_issue');
            }

            $checked[] = [
                ...$row,
                'party_id' => (string) ($line['party_id'] ?? ''),
                'debit_minor' => $kind === 'customer' ? $amount : 0,
                'credit_minor' => $kind === 'vendor' ? $amount : 0,
                'reference' => isset($line['reference']) ? (string) $line['reference'] : null,
                'issue_date' => $issued->toDateString(),
                'due_date' => $due?->toDateString() ?? ($party === null ? $issued->toDateString() : $this->documents->dueDate($company, $party, $issued)),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $checked;
    }

    /**
     * The journal: account lines as written, each customer item on the
     * receivable account and each vendor item on the payable one, and the
     * difference on the opening balance account.
     *
     * @return list<array{account_id: string, cost_centre_id: string, debit_minor: int, credit_minor: int, memo: string|null}>
     */
    private function journalLines(Organization $company, Opening $opening): array
    {
        $rows = $this->lines($company, $opening);
        if ($rows->isEmpty()) {
            throw AccountingException::tooFewLines();
        }
        $parties = $this->books->query(Party::class, $company)->whereKey($rows->pluck('party_id')->filter()->unique()->values()->all())->pluck('name', 'id');

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = [
                'account_id' => $row->kind === 'account' ? (string) $row->account_id : $this->postingAccount($company, $row->kind === 'customer' ? 'accounting.receivable' : 'accounting.payable'),
                'cost_centre_id' => $row->cost_centre_id,
                'debit_minor' => $row->debit_minor,
                'credit_minor' => $row->credit_minor,
                'memo' => $row->kind === 'account' ? null : mb_substr(trim(($parties[$row->party_id] ?? '').' '.($row->reference ?? '')), 0, 255),
            ];
        }

        $difference = self::totals($rows)['difference_minor'];
        if ($difference !== 0) {
            $lines[] = [
                'account_id' => $this->postingAccount($company, 'accounting.opening_balance'),
                'cost_centre_id' => $rows->first()->cost_centre_id,
                'debit_minor' => max(-$difference, 0),
                'credit_minor' => max($difference, 0),
                'memo' => null,
            ];
        }

        return $lines;
    }

    /** Into the books: one journal, then an opening invoice or bill per customer or vendor item. */
    private function post(Organization $company, Opening $opening, ?User $approver, User $actor): Opening
    {
        $journal = $this->journals->draft($company, [
            'entry_date' => $opening->opening_date->toDateString(),
            'narration' => mb_substr(__('accounting::accounting.opening_narration'), 0, 500),
            'lines' => $this->journalLines($company, $opening),
        ], $actor, ['module' => 'accounting', 'type' => self::SOURCE_TYPE, 'id' => $opening->getKey()]);
        // People date openings in the past on purpose: only an open period is needed, not the backdating window.
        $journal = $this->journals->postApproved($company, $journal, $approver, $actor);

        $counter = ['customer' => 0, 'vendor' => 0];
        $openingAccount = $this->postingAccount($company, 'accounting.opening_balance');
        foreach ($this->lines($company, $opening)->where('kind', '!=', 'account') as $row) {
            $counter[$row->kind]++;
            $amount = $row->amount();
            $document = new Document;
            $document->forceFill([
                'organization_id' => $company->getKey(),
                'type' => $row->kind === 'customer' ? DocumentType::Invoice : DocumentType::Bill,
                'number' => 'OB-'.str_pad((string) $counter[$row->kind], 5, '0', STR_PAD_LEFT),
                'party_id' => $row->party_id,
                'issue_date' => $row->issue_date->toDateString(),
                'due_date' => $row->due_date->toDateString(),
                'reference' => $row->reference,
                'status' => DocumentStatus::Posted,
                'currency_code' => $journal->currency_code,
                'net_minor' => $amount,
                'tax_minor' => 0,
                'total_minor' => $amount,
                'prices_include_tax' => false,
                'allocated_minor' => 0,
                'journal_id' => $journal->getKey(),
                'created_by' => $opening->created_by,
                'submitted_by' => $opening->submitted_by,
                'approved_by' => $approver?->getKey(),
                'posted_at' => now(),
                'is_opening' => true,
                'version' => 1,
            ])->save();

            $line = new DocumentLine;
            $line->fill([
                'organization_id' => $company->getKey(), 'document_id' => $document->getKey(), 'line_no' => 1,
                'description' => mb_substr(__('accounting::accounting.opening_document_line'), 0, 255),
                'quantity_milli' => Documents::MILLI, 'unit_price_minor' => $amount, 'amount_minor' => $amount,
                'account_id' => $openingAccount, 'cost_centre_id' => $row->cost_centre_id,
                'tax_code_id' => null, 'tax_rate_bp' => 0, 'tax_minor' => 0,
            ])->save();
        }

        $opening->forceFill([
            'status' => JournalStatus::Posted, 'journal_id' => $journal->getKey(), 'approved_by' => $approver?->getKey(),
            'posted_at' => now(), 'version' => $opening->version + 1,
        ])->save();
        $this->audit->record('accounting.opening_posted', $opening, new: [...$this->auditValues($company, $opening), 'journal' => $journal->number], actor: $actor, organizationId: $company->getKey());

        return $opening;
    }

    private function postingAccount(Organization $company, string $key): string
    {
        return (string) ($this->books->query(PostingAccount::class, $company)->where('posting_key', $key)->value('account_id')
            ?? throw AccountingException::postingAccountMissing($key));
    }

    private function pending(Organization $company, int $baseVersion, User $actor): Opening
    {
        $opening = $this->lock($company, $baseVersion);
        if ($opening->status !== JournalStatus::PendingApproval) {
            throw AccountingException::notPending();
        }
        if (in_array($actor->getKey(), [$opening->created_by, $opening->submitted_by], true)) {
            throw AccountingException::ownJournal();
        }

        return $opening;
    }

    private function lock(Organization $company, int $baseVersion): Opening
    {
        $opening = $this->books->query(Opening::class, $company)->lockForUpdate()->first() ?? throw AccountingException::openingNotFound();
        $this->assertVersion($opening, $baseVersion);

        return $opening;
    }

    private function assertVersion(Opening $opening, ?int $baseVersion): void
    {
        if ($opening->version !== $baseVersion) {
            throw AccountingException::versionConflict(['version' => $opening->version, 'status' => $opening->status->value]);
        }
    }

    private function assertEditable(Opening $opening): void
    {
        if ($opening->status === JournalStatus::Posted) {
            throw AccountingException::openingPosted();
        }
        if (! $opening->status->isEditable()) {
            throw AccountingException::notEditable();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(Organization $company, Opening $opening): array
    {
        return [
            'opening_date' => $opening->opening_date->toDateString(), 'status' => $opening->status->value,
            ...self::totals($this->lines($company, $opening)), 'currency' => $this->books->currency($company),
        ];
    }
}
