<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Enums\DocumentStatus;
use Modules\Accounting\Enums\DocumentType;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Models\PostingAccount;
use Modules\Accounting\Models\TaxCode;

/**
 * Invoices, credit notes, bills and vendor credits:
 *
 *   draft -> submit -> posted (a journal: receivable/payable against the lines' accounts)
 *                   -> pending_approval -> approve -> posted | reject -> rejected (edit, submit again)
 *   posted -> paid by settlements or credits (partly_paid, paid) | void (journal reversed; nothing paid)
 *
 * Approval works as for journals (accounting.journal_approval_above, never
 * the writer); the approved document's journal is posted without a second
 * approval. Dates follow the company's date window; due dates its payment
 * terms (the party's own terms first).
 */
class Documents
{
    /** Thousandths in one unit of quantity. */
    public const MILLI = 1000;

    public function __construct(
        private Books $books,
        private Journals $journals,
        private JournalNumbers $numbers,
        private FiscalCalendar $calendar,
        private Allocations $allocations,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by DocumentRequest.
     */
    public function draft(Organization $company, DocumentType $type, array $data, User $actor): Document
    {
        $this->books->assertSetUp($company);
        $party = $this->party($company, $type, $data['party_id']);
        $inclusive = (bool) $this->rules->get('accounting.prices_include_tax', $this->contexts->forOrganization($company));
        $lines = $this->checkLines($company, $type, $data['lines'], $inclusive);
        $issued = CarbonImmutable::parse($data['issue_date'], 'UTC');

        return $this->books->transaction($company, function () use ($company, $type, $data, $party, $lines, $inclusive, $issued, $actor) {
            $document = new Document;
            $document->fill([
                'organization_id' => $company->getKey(),
                'type' => $type,
                'party_id' => $party->getKey(),
                'issue_date' => $issued->toDateString(),
                'due_date' => $data['due_date'] ?? $this->dueDate($company, $party, $issued),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => DocumentStatus::Draft,
                'currency_code' => $this->books->currency($company),
                ...self::totals($lines),
                'prices_include_tax' => $inclusive,
                'created_by' => $actor->getKey(),
                'version' => 1,
            ])->save();
            $this->writeLines($company, $document, $lines);

            $this->audit->record('accounting.document_drafted', $document, new: $this->auditValues($document), actor: $actor, organizationId: $company->getKey());

            return $document;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields to change.
     */
    public function update(Organization $company, Document $document, int $baseVersion, array $data, User $actor): Document
    {
        return $this->books->transaction($company, function () use ($company, $document, $baseVersion, $data, $actor) {
            $document = $this->lock($company, $document, $baseVersion);
            if (! $document->status->isEditable()) {
                throw AccountingException::notEditable();
            }
            $old = $this->auditValues($document);

            if (isset($data['party_id'])) {
                $document->party_id = $this->party($company, $document->type, $data['party_id'])->getKey();
            }
            $document->fill(array_intersect_key($data, array_flip(['issue_date', 'due_date', 'reference', 'notes'])));
            if (isset($data['lines'])) {
                $lines = $this->checkLines($company, $document->type, $data['lines'], $document->prices_include_tax);
                $this->books->query(DocumentLine::class, $company)->where('document_id', $document->getKey())->delete();
                $this->writeLines($company, $document, $lines);
                $document->fill(self::totals($lines));
            }
            $document->forceFill(['status' => DocumentStatus::Draft, 'reject_reason' => null, 'version' => $document->version + 1])->save();

            $this->audit->record('accounting.document_updated', $document, old: $old, new: $this->auditValues($document), actor: $actor, organizationId: $company->getKey());

            return $document;
        });
    }

    public function delete(Organization $company, Document $document, int $baseVersion, User $actor): void
    {
        $this->books->transaction($company, function () use ($company, $document, $baseVersion, $actor) {
            $document = $this->lock($company, $document, $baseVersion);
            if (! $document->status->isEditable()) {
                throw AccountingException::notEditable();
            }

            $this->audit->record('accounting.document_deleted', $document, old: $this->auditValues($document), actor: $actor, organizationId: $company->getKey());
            $this->books->query(DocumentLine::class, $company)->where('document_id', $document->getKey())->delete();
            $document->delete();
        });
    }

    public function submit(Organization $company, Document $document, int $baseVersion, User $actor): Document
    {
        return $this->books->transaction($company, function () use ($company, $document, $baseVersion, $actor) {
            $document = $this->lock($company, $document, $baseVersion);
            if (! $document->status->isEditable()) {
                throw AccountingException::notEditable();
            }
            if ($document->total_minor <= 0) {
                throw AccountingException::unbalanced($document->total_minor, $document->total_minor);
            }
            $this->party($company, $document->type, $document->party_id);
            $this->journals->assertDateAllowed($company, $document->issue_date);

            if ($this->journals->amountNeedsApproval($company, $document->total_minor, $document->currency_code)) {
                // Fail early: the period must be open now as well as at approval.
                $this->calendar->openPeriodFor($company, $document->issue_date);
                $document->forceFill(['status' => DocumentStatus::PendingApproval, 'submitted_by' => $actor->getKey(), 'version' => $document->version + 1])->save();
                $this->audit->record('accounting.document_submitted', $document, new: $this->auditValues($document), actor: $actor, organizationId: $company->getKey());

                return $document;
            }

            $document->submitted_by = $actor->getKey();

            return $this->post($company, $document, null, $actor);
        });
    }

    public function approve(Organization $company, Document $document, int $baseVersion, User $actor): Document
    {
        return $this->books->transaction($company, function () use ($company, $document, $baseVersion, $actor) {
            $document = $this->pending($company, $document, $baseVersion, $actor);

            return $this->post($company, $document, $actor, $actor);
        });
    }

    public function reject(Organization $company, Document $document, int $baseVersion, string $reason, User $actor): Document
    {
        return $this->books->transaction($company, function () use ($company, $document, $baseVersion, $reason, $actor) {
            $document = $this->pending($company, $document, $baseVersion, $actor);
            $document->forceFill(['status' => DocumentStatus::Rejected, 'reject_reason' => $reason, 'version' => $document->version + 1])->save();
            $this->audit->record('accounting.document_rejected', $document, new: $this->auditValues($document), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $document;
        });
    }

    /**
     * Cancel a posted document: its journal is reversed today. Only while
     * nothing is paid against it (or, for a credit, nothing of it is used):
     * void those settlements first.
     */
    public function void(Organization $company, Document $document, int $baseVersion, string $reason, User $actor): Document
    {
        return $this->books->transaction($company, function () use ($company, $document, $baseVersion, $reason, $actor) {
            $document = $this->lock($company, $document, $baseVersion);
            if (! $document->status->isPosted()) {
                throw AccountingException::notPosted();
            }
            if ($document->allocated_minor > 0) {
                throw AccountingException::documentHasPayments();
            }
            // Opening items share the opening's journal: they are corrected with a credit note.
            if ($document->is_opening) {
                throw AccountingException::openingDocument();
            }

            /** @var Journal $journal */
            $journal = $this->books->query(Journal::class, $company)->findOrFail($document->journal_id);
            $this->journals->reverse($company, $journal, null, $reason, $actor, fromSource: true);

            $document->forceFill(['status' => DocumentStatus::Void, 'voided_at' => now(), 'void_reason' => $reason, 'version' => $document->version + 1])->save();
            $this->audit->record('accounting.document_voided', $document, new: $this->auditValues($document), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $document;
        });
    }

    /**
     * Use a credit note (vendor credit) against invoices (bills) of the same
     * party. No journal: both already sit in the party account.
     *
     * @param  list<array{document_id: string, amount_minor: int}>  $requested
     */
    public function applyCredit(Organization $company, Document $credit, int $baseVersion, array $requested, User $actor): Document
    {
        if (! $credit->type->isCredit()) {
            throw AccountingException::notACredit();
        }

        return $this->books->transaction($company, function () use ($company, $credit, $baseVersion, $requested, $actor) {
            $credit = $this->lock($company, $credit, $baseVersion);
            if (! $credit->status->isPosted()) {
                throw AccountingException::documentNotOpen();
            }
            $asked = $this->allocations->check($company, $credit->party_id, $credit->type->owedType(), $requested);
            if ($asked > $credit->balance()) {
                throw ValidationException::withMessages(['allocations' => __('accounting::accounting.validation.allocation_more_than_left')]);
            }

            $used = $this->allocations->apply($company, ['credit_document_id' => $credit->getKey()], $requested, $this->books->today($company), $actor, $credit->party_id, $credit->type->owedType());
            $this->allocations->settle($credit, $credit->allocated_minor + $used);
            $this->audit->record('accounting.credit_applied', $credit, new: ['amount_minor' => $used, 'documents' => array_column($requested, 'document_id')], actor: $actor, organizationId: $company->getKey());

            return $credit;
        });
    }

    /**
     * Lines as written: description, quantity (up to 3 decimals, as text),
     * unit price in minor units, an account of the right type, a cost centre
     * inside the company, optionally a tax code of this side. Quantity x price
     * (rounded half up) is the net amount, or with prices including tax the
     * gross one; TaxCodes::split works out the net and the tax.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{description: string, quantity_milli: int, unit_price_minor: int, amount_minor: int, account_id: string, cost_centre_id: string, tax_code_id: string|null, tax_rate_bp: int, tax_minor: int}>
     */
    public function checkLines(Organization $company, DocumentType $type, array $lines, bool $pricesIncludeTax = false): array
    {
        $taxCodes = $this->books->query(TaxCode::class, $company)->whereKey(array_values(array_filter(array_column($lines, 'tax_code_id'), 'is_string')))->get()->keyBy('id');
        $side = $type->isSales() ? 'sales' : 'purchases';
        $accounts = $this->books->query(Account::class, $company)->whereKey(array_values(array_filter(array_column($lines, 'account_id'), 'is_string')))->get()->keyBy('id');
        $needsUnit = (bool) $this->rules->get('accounting.require_cost_centre', $this->contexts->forOrganization($company));
        $allowedTypes = $type->lineAccountTypes();

        $errors = [];
        $checked = [];
        foreach (array_values($lines) as $index => $line) {
            $account = $accounts[$line['account_id'] ?? ''] ?? null;
            if ($account === null || ! $account->isPostable() || ! in_array($account->type, $allowedTypes, true)) {
                $errors["lines.{$index}.account_id"] = __('accounting::accounting.validation.line_account_'.($type->isSales() ? 'sales' : 'purchases'));
            }
            $costCentre = $this->books->costCentre($company, $line['cost_centre_id'] ?? null);
            if ($costCentre === null) {
                $errors["lines.{$index}.cost_centre_id"] = __('accounting::accounting.validation.cost_centre_outside');
            } elseif ($needsUnit && $costCentre === $company->getKey()) {
                $errors["lines.{$index}.cost_centre_id"] = __('accounting::accounting.validation.cost_centre_required');
            }

            $quantity = self::quantityMilli((string) ($line['quantity'] ?? '1'));
            $price = (int) ($line['unit_price_minor'] ?? 0);
            if ($quantity === null || $quantity === 0) {
                $errors["lines.{$index}.quantity"] = __('accounting::accounting.validation.quantity');
            }
            $taxCode = isset($line['tax_code_id']) ? ($taxCodes[$line['tax_code_id']] ?? null) : null;
            if (isset($line['tax_code_id']) && ($taxCode === null || ! $taxCode->appliesTo($side))) {
                $errors["lines.{$index}.tax_code_id"] = __('accounting::accounting.validation.tax_code');
            }
            $split = TaxCodes::split(self::amount((int) $quantity, $price), $taxCode?->rate_bp ?? 0, $pricesIncludeTax);

            $checked[] = [
                'description' => (string) $line['description'],
                'quantity_milli' => (int) $quantity,
                'unit_price_minor' => $price,
                'amount_minor' => $split['net'],
                'account_id' => (string) ($line['account_id'] ?? ''),
                'cost_centre_id' => (string) $costCentre,
                'tax_code_id' => $taxCode?->getKey(),
                'tax_rate_bp' => $taxCode?->rate_bp ?? 0,
                'tax_minor' => $split['tax'],
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $checked;
    }

    /**
     * A document's net, tax and total from its checked lines.
     *
     * @param  list<array{amount_minor: int, tax_minor: int}>  $lines
     * @return array{net_minor: int, tax_minor: int, total_minor: int}
     */
    public static function totals(array $lines): array
    {
        $net = array_sum(array_column($lines, 'amount_minor'));
        $tax = array_sum(array_column($lines, 'tax_minor'));

        return ['net_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $net + $tax];
    }

    /** "1.5" -> 1500 thousandths; null when it is not a quantity with at most 3 decimals. */
    public static function quantityMilli(string $text): ?int
    {
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/', trim($text), $match)) {
            return null;
        }

        return (int) $match[1] * self::MILLI + (int) str_pad($match[2] ?? '', 3, '0');
    }

    /** Quantity x unit price, half up, in minor units (integers only). */
    public static function amount(int $quantityMilli, int $unitPriceMinor): int
    {
        return intdiv($quantityMilli * $unitPriceMinor + intdiv(self::MILLI, 2), self::MILLI);
    }

    /** Due date from the party's own terms, else the company's rule. */
    public function dueDate(Organization $company, Party $party, CarbonImmutable $issued): string
    {
        $days = $party->payment_terms_days ?? (int) $this->rules->get('accounting.payment_terms_days', $this->contexts->forOrganization($company));

        return $issued->addDays($days)->toDateString();
    }

    /**
     * Into the books: number, a journal (party account against the lines'
     * accounts, credits the other way round) posted at once.
     */
    private function post(Organization $company, Document $document, ?User $approver, User $actor): Document
    {
        $period = $this->calendar->openPeriodFor($company, $document->issue_date);
        /** @var FiscalYear $year */
        $year = $this->books->query(FiscalYear::class, $company)->findOrFail($period->fiscal_year_id);
        $number = $this->numbers->next($company, $year, $document->type->value);
        $party = $this->books->query(Party::class, $company)->findOrFail($document->party_id);

        $partyAccount = $this->books->query(PostingAccount::class, $company)->where('posting_key', $document->type->partyPostingKey())->value('account_id')
            ?? throw AccountingException::postingAccountMissing($document->type->partyPostingKey());

        // Sales invoice: debit what the customer owes, credit income. Bill: debit expenses, credit what is owed. Credits: the other way.
        $partyOnDebit = $document->type->isSales() !== $document->type->isCredit();
        $documentLines = $this->books->query(DocumentLine::class, $company)->where('document_id', $document->getKey())->orderBy('line_no')->get();
        $lines = [[
            'account_id' => $partyAccount,
            // The party line takes the first line's branch or department (needed when the rule asks for one).
            'cost_centre_id' => $documentLines->first()?->cost_centre_id,
            'debit_minor' => $partyOnDebit ? $document->total_minor : 0,
            'credit_minor' => $partyOnDebit ? 0 : $document->total_minor,
            'memo' => $party->name,
        ]];
        foreach ($documentLines as $line) {
            $lines[] = [
                'account_id' => $line->account_id,
                'cost_centre_id' => $line->cost_centre_id,
                'debit_minor' => $partyOnDebit ? 0 : $line->amount_minor,
                'credit_minor' => $partyOnDebit ? $line->amount_minor : 0,
                'memo' => mb_substr($line->description, 0, 255),
            ];
        }
        // Tax per code: output tax on sales, input tax on purchases (posting keys accounting.tax_output / tax_input).
        $taxKey = $document->type->isSales() ? 'accounting.tax_output' : 'accounting.tax_input';
        foreach ($documentLines->where('tax_minor', '>', 0)->groupBy('tax_code_id') as $taxLines) {
            $tax = (int) $taxLines->sum('tax_minor');
            $lines[] = [
                'account_id' => $this->books->query(PostingAccount::class, $company)->where('posting_key', $taxKey)->value('account_id') ?? throw AccountingException::postingAccountMissing($taxKey),
                'cost_centre_id' => $taxLines->first()->cost_centre_id,
                'debit_minor' => $partyOnDebit ? 0 : $tax,
                'credit_minor' => $partyOnDebit ? $tax : 0,
                'memo' => $this->books->query(TaxCode::class, $company)->whereKey($taxLines->first()->tax_code_id)->value('code'),
            ];
        }
        // Zero lines (free items) carry no amount in the books.
        $lines = array_values(array_filter($lines, fn (array $line) => $line['debit_minor'] + $line['credit_minor'] > 0));

        $journal = $this->journals->draft($company, [
            'entry_date' => $document->issue_date->toDateString(),
            'narration' => mb_substr(__('accounting::accounting.document_narration.'.$document->type->value, ['number' => $number, 'party' => $party->name]), 0, 500),
            'lines' => $lines,
        ], $actor, ['module' => 'accounting', 'type' => 'document', 'id' => $document->getKey()]);
        $journal = $this->journals->postApproved($company, $journal, $approver, $actor);

        $document->forceFill([
            'status' => DocumentStatus::Posted,
            'number' => $number,
            'journal_id' => $journal->getKey(),
            'approved_by' => $approver?->getKey(),
            'posted_at' => now(),
            'version' => $document->version + 1,
        ])->save();
        $this->audit->record('accounting.document_posted', $document, new: $this->auditValues($document), actor: $actor, organizationId: $company->getKey());

        return $document;
    }

    /** An active party of the right kind for this document. */
    private function party(Organization $company, DocumentType $type, string $id): Party
    {
        $party = $this->books->query(Party::class, $company)->whereKey($id)->first();
        if ($party === null || ! $party->is_active || ! ($type->isSales() ? $party->is_customer : $party->is_vendor)) {
            throw ValidationException::withMessages(['party_id' => __('accounting::accounting.validation.party_'.($type->isSales() ? 'customer' : 'vendor'))]);
        }

        return $party;
    }

    private function pending(Organization $company, Document $document, int $baseVersion, User $actor): Document
    {
        $document = $this->lock($company, $document, $baseVersion);
        if ($document->status !== DocumentStatus::PendingApproval) {
            throw AccountingException::notPending();
        }
        if (in_array($actor->getKey(), [$document->created_by, $document->submitted_by], true)) {
            throw AccountingException::ownJournal();
        }

        return $document;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(Organization $company, Document $document, array $lines): void
    {
        foreach ($lines as $index => $line) {
            $row = new DocumentLine;
            $row->fill([...$line, 'organization_id' => $company->getKey(), 'document_id' => $document->getKey(), 'line_no' => $index + 1])->save();
        }
    }

    private function lock(Organization $company, Document $document, int $baseVersion): Document
    {
        /** @var Document $fresh */
        $fresh = $this->books->query(Document::class, $company)->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw AccountingException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status->value]);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(Document $document): array
    {
        return [
            'type' => $document->type->value, 'number' => $document->number, 'party_id' => $document->party_id,
            'issue_date' => $document->issue_date->toDateString(), 'status' => $document->status->value,
            'total_minor' => $document->total_minor, 'currency' => $document->currency_code,
        ];
    }
}
