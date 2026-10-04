<?php

namespace Modules\Accounting\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Allocation;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Models\PostingAccount;
use Modules\Accounting\Models\Settlement;

/**
 * The books in the client's data export: accounts, fiscal years and periods,
 * journals with their lines, posting accounts. Amounts stay minor units with
 * their currency. Runs without a tenant context, reading the client's own
 * database for exactly the organizations given.
 */
class AccountingExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'accounting';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        return [
            'accounts' => $this->rows(Account::class, $organization, $organizationIds, fn (Account $account) => [
                'id' => $account->getKey(),
                'organization_id' => $account->organization_id,
                'parent_id' => $account->parent_id,
                'code' => $account->code,
                'name' => json_encode($account->texts('name'), JSON_UNESCAPED_UNICODE),
                'type' => $account->type->value,
                'is_group' => $account->is_group,
                'status' => $account->status->value,
            ]),
            'fiscal_years' => $this->rows(FiscalYear::class, $organization, $organizationIds, fn (FiscalYear $year) => [
                'id' => $year->getKey(),
                'organization_id' => $year->organization_id,
                'name' => $year->name,
                'starts_on' => $year->starts_on->toDateString(),
                'ends_on' => $year->ends_on->toDateString(),
            ]),
            'periods' => $this->rows(Period::class, $organization, $organizationIds, fn (Period $period) => [
                'id' => $period->getKey(),
                'fiscal_year_id' => $period->fiscal_year_id,
                'number' => $period->number,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'status' => $period->status->value,
            ]),
            'journals' => $this->rows(Journal::class, $organization, $organizationIds, fn (Journal $journal) => [
                'id' => $journal->getKey(),
                'organization_id' => $journal->organization_id,
                'number' => $journal->number,
                'entry_date' => $journal->entry_date->toDateString(),
                'narration' => $journal->narration,
                'status' => $journal->status->value,
                'currency_code' => $journal->currency_code,
                'total_minor' => $journal->total_minor,
                'source_module' => $journal->source_module,
                'source_type' => $journal->source_type,
                'source_id' => $journal->source_id,
                'reverses_id' => $journal->reverses_id,
                'posted_at' => $journal->posted_at?->toIso8601String(),
            ]),
            'journal_lines' => $this->rows(JournalLine::class, $organization, $organizationIds, fn (JournalLine $line) => [
                'id' => $line->getKey(),
                'journal_id' => $line->journal_id,
                'line_no' => $line->line_no,
                'account_id' => $line->account_id,
                'cost_centre_id' => $line->cost_centre_id,
                'debit_minor' => $line->debit_minor,
                'credit_minor' => $line->credit_minor,
                'memo' => $line->memo,
            ]),
            'posting_accounts' => $this->rows(PostingAccount::class, $organization, $organizationIds, fn (PostingAccount $mapping) => [
                'id' => $mapping->getKey(),
                'organization_id' => $mapping->organization_id,
                'posting_key' => $mapping->posting_key,
                'account_id' => $mapping->account_id,
            ]),
            'parties' => $this->rows(Party::class, $organization, $organizationIds, fn (Party $party) => [
                'id' => $party->getKey(),
                'organization_id' => $party->organization_id,
                'name' => $party->name,
                'code' => $party->code,
                'is_customer' => $party->is_customer,
                'is_vendor' => $party->is_vendor,
                'phone' => $party->phone,
                'email' => $party->email,
                'address' => $party->address === null ? null : json_encode($party->address, JSON_UNESCAPED_UNICODE),
                'tax_number' => $party->tax_number,
                'payment_terms_days' => $party->payment_terms_days,
                'is_active' => $party->is_active,
            ]),
            'documents' => $this->rows(Document::class, $organization, $organizationIds, fn (Document $document) => [
                'id' => $document->getKey(),
                'type' => $document->type->value,
                'number' => $document->number,
                'party_id' => $document->party_id,
                'issue_date' => $document->issue_date->toDateString(),
                'due_date' => $document->due_date->toDateString(),
                'reference' => $document->reference,
                'status' => $document->status->value,
                'currency_code' => $document->currency_code,
                'total_minor' => $document->total_minor,
                'allocated_minor' => $document->allocated_minor,
                'journal_id' => $document->journal_id,
            ]),
            'document_lines' => $this->rows(DocumentLine::class, $organization, $organizationIds, fn (DocumentLine $line) => [
                'id' => $line->getKey(),
                'document_id' => $line->document_id,
                'line_no' => $line->line_no,
                'description' => $line->description,
                'quantity_milli' => $line->quantity_milli,
                'unit_price_minor' => $line->unit_price_minor,
                'amount_minor' => $line->amount_minor,
                'account_id' => $line->account_id,
                'cost_centre_id' => $line->cost_centre_id,
            ]),
            'settlements' => $this->rows(Settlement::class, $organization, $organizationIds, fn (Settlement $settlement) => [
                'id' => $settlement->getKey(),
                'type' => $settlement->type->value,
                'number' => $settlement->number,
                'party_id' => $settlement->party_id,
                'settled_on' => $settlement->settled_on->toDateString(),
                'account_id' => $settlement->account_id,
                'amount_minor' => $settlement->amount_minor,
                'allocated_minor' => $settlement->allocated_minor,
                'currency_code' => $settlement->currency_code,
                'reference' => $settlement->reference,
                'status' => $settlement->status->value,
                'journal_id' => $settlement->journal_id,
            ]),
            'allocations' => $this->rows(Allocation::class, $organization, $organizationIds, fn (Allocation $allocation) => [
                'id' => $allocation->getKey(),
                'settlement_id' => $allocation->settlement_id,
                'credit_document_id' => $allocation->credit_document_id,
                'document_id' => $allocation->document_id,
                'amount_minor' => $allocation->amount_minor,
                'allocated_on' => $allocation->allocated_on->toDateString(),
                'voided' => $allocation->voided_at !== null,
            ]),
        ];
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  list<string>  $organizationIds
     * @param  callable(T): array<string, scalar|null>  $row
     * @return iterable<array<string, scalar|null>>
     */
    private function rows(string $model, Organization $organization, array $organizationIds, callable $row): iterable
    {
        foreach (array_chunk($organizationIds, 500) as $chunk) {
            foreach ($model::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $chunk)->orderBy('id')->lazy(500) as $record) {
                yield $row($record);
            }
        }
    }
}
