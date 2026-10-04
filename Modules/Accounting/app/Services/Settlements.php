<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\SettlementStatus;
use Modules\Accounting\Enums\SettlementType;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Models\PostingAccount;
use Modules\Accounting\Models\Settlement;

/**
 * Money received from customers (receipts) and paid to vendors (payments),
 * into or out of a cash, bank or wallet account, set against the party's
 * invoices or bills. Money records: written once (op_id makes a repeat
 * harmless), posted at once or after approval above the company's amount,
 * voided (journal reversed, documents owed again), never changed or removed.
 * Unless accounting.allow_overpayment, everything received must pay documents.
 */
class Settlements
{
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
     * @param  array<string, mixed>  $data  Validated by SettlementRequest.
     */
    public function record(Organization $company, SettlementType $type, array $data, User $actor): Settlement
    {
        $this->books->assertSetUp($company);
        if (isset($data['op_id']) && ($existing = $this->books->query(Settlement::class, $company)->where('op_id', $data['op_id'])->first()) !== null) {
            return $existing;
        }

        $party = $this->party($company, $type, $data['party_id']);
        $account = $this->moneyAccount($company, $data['account_id']);
        $amount = (int) $data['amount_minor'];
        $requested = array_values($data['allocations'] ?? []);
        $this->checkAllocations($company, $type, $party, $amount, $requested);
        $on = CarbonImmutable::parse($data['settled_on'], 'UTC');
        $this->journals->assertDateAllowed($company, $on);

        try {
            return $this->books->transaction($company, function () use ($company, $type, $data, $party, $account, $amount, $requested, $on, $actor) {
                $settlement = new Settlement;
                $settlement->fill([
                    'organization_id' => $company->getKey(),
                    'type' => $type,
                    'party_id' => $party->getKey(),
                    'settled_on' => $on->toDateString(),
                    'account_id' => $account->getKey(),
                    'amount_minor' => $amount,
                    'currency_code' => $this->books->currency($company),
                    'reference' => $data['reference'] ?? null,
                    'memo' => $data['memo'] ?? null,
                    'status' => SettlementStatus::PendingApproval,
                    'requested_allocations' => $requested,
                    'op_id' => $data['op_id'] ?? null,
                    'created_by' => $actor->getKey(),
                    'version' => 1,
                ])->save();

                if ($this->journals->amountNeedsApproval($company, $amount, $settlement->currency_code)) {
                    $this->calendar->openPeriodFor($company, $on);
                    $this->audit->record('accounting.settlement_submitted', $settlement, new: $this->auditValues($settlement), actor: $actor, organizationId: $company->getKey());

                    return $settlement;
                }

                return $this->post($company, $settlement, null, $actor);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The same money arrived twice at once: the other one was saved.
            return $this->books->query(Settlement::class, $company)->where('op_id', $data['op_id'] ?? '')->first() ?? throw $exception;
        }
    }

    public function approve(Organization $company, Settlement $settlement, int $baseVersion, User $actor): Settlement
    {
        return $this->books->transaction($company, function () use ($company, $settlement, $baseVersion, $actor) {
            $settlement = $this->pending($company, $settlement, $baseVersion, $actor);

            return $this->post($company, $settlement, $actor, $actor);
        });
    }

    public function reject(Organization $company, Settlement $settlement, int $baseVersion, string $reason, User $actor): Settlement
    {
        return $this->books->transaction($company, function () use ($company, $settlement, $baseVersion, $reason, $actor) {
            $settlement = $this->pending($company, $settlement, $baseVersion, $actor);
            $settlement->forceFill(['status' => SettlementStatus::Rejected, 'reject_reason' => $reason, 'version' => $settlement->version + 1])->save();
            $this->audit->record('accounting.settlement_rejected', $settlement, new: $this->auditValues($settlement), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $settlement;
        });
    }

    /** Cancel posted money: its journal is reversed today and the documents it paid are owed again. */
    public function void(Organization $company, Settlement $settlement, int $baseVersion, string $reason, User $actor): Settlement
    {
        return $this->books->transaction($company, function () use ($company, $settlement, $baseVersion, $reason, $actor) {
            $settlement = $this->lock($company, $settlement, $baseVersion);
            if ($settlement->status !== SettlementStatus::Posted) {
                throw AccountingException::settlementNotPosted();
            }

            /** @var Journal $journal */
            $journal = $this->books->query(Journal::class, $company)->findOrFail($settlement->journal_id);
            $this->journals->reverse($company, $journal, null, $reason, $actor, fromSource: true);
            $this->allocations->release($company, ['settlement_id' => $settlement->getKey()]);

            $settlement->forceFill([
                'status' => SettlementStatus::Void, 'allocated_minor' => 0, 'voided_at' => now(), 'void_reason' => $reason, 'version' => $settlement->version + 1,
            ])->save();
            $this->audit->record('accounting.settlement_voided', $settlement, new: $this->auditValues($settlement), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $settlement;
        });
    }

    /**
     * Set money not yet allocated (an advance) against documents later.
     *
     * @param  list<array{document_id: string, amount_minor: int}>  $requested
     */
    public function allocate(Organization $company, Settlement $settlement, int $baseVersion, array $requested, User $actor): Settlement
    {
        return $this->books->transaction($company, function () use ($company, $settlement, $baseVersion, $requested, $actor) {
            $settlement = $this->lock($company, $settlement, $baseVersion);
            if ($settlement->status !== SettlementStatus::Posted) {
                throw AccountingException::settlementNotPosted();
            }
            $asked = $this->allocations->check($company, $settlement->party_id, $settlement->type->documentType(), $requested);
            if ($settlement->unallocated() <= 0) {
                throw AccountingException::nothingToAllocate();
            }
            if ($asked > $settlement->unallocated()) {
                throw ValidationException::withMessages(['allocations' => __('accounting::accounting.validation.allocation_more_than_left')]);
            }

            $added = $this->allocations->apply($company, ['settlement_id' => $settlement->getKey()], $requested, $this->books->today($company), $actor, $settlement->party_id, $settlement->type->documentType());
            $settlement->forceFill(['allocated_minor' => $settlement->allocated_minor + $added, 'version' => $settlement->version + 1])->save();
            $this->audit->record('accounting.settlement_allocated', $settlement, new: ['amount_minor' => $added, 'documents' => array_column($requested, 'document_id')], actor: $actor, organizationId: $company->getKey());

            return $settlement;
        });
    }

    /**
     * Into the books: a number, a journal (money account against the party
     * account) posted at once, and the allocations asked for.
     */
    private function post(Organization $company, Settlement $settlement, ?User $approver, User $actor): Settlement
    {
        $period = $this->calendar->openPeriodFor($company, $settlement->settled_on);
        /** @var FiscalYear $year */
        $year = $this->books->query(FiscalYear::class, $company)->findOrFail($period->fiscal_year_id);
        $number = $this->numbers->next($company, $year, $settlement->type->value);
        $party = $this->books->query(Party::class, $company)->findOrFail($settlement->party_id);
        $partyAccount = $this->books->query(PostingAccount::class, $company)->where('posting_key', $settlement->type->partyPostingKey())->value('account_id')
            ?? throw AccountingException::postingAccountMissing($settlement->type->partyPostingKey());

        // A receipt: money in (debit) against what the customer owed (credit). A payment: the other way.
        $receipt = $settlement->type === SettlementType::Receipt;
        $journal = $this->journals->draft($company, [
            'entry_date' => $settlement->settled_on->toDateString(),
            'narration' => mb_substr(__('accounting::accounting.document_narration.'.$settlement->type->value, ['number' => $number, 'party' => $party->name]), 0, 500),
            'lines' => [
                ['account_id' => $settlement->account_id, 'debit_minor' => $receipt ? $settlement->amount_minor : 0, 'credit_minor' => $receipt ? 0 : $settlement->amount_minor, 'memo' => $settlement->reference],
                ['account_id' => $partyAccount, 'debit_minor' => $receipt ? 0 : $settlement->amount_minor, 'credit_minor' => $receipt ? $settlement->amount_minor : 0, 'memo' => $party->name],
            ],
        ], $actor, ['module' => 'accounting', 'type' => 'settlement', 'id' => $settlement->getKey()]);
        $journal = $this->journals->postApproved($company, $journal, $approver, $actor);

        $requested = $settlement->requested_allocations ?? [];
        $allocated = $requested === [] ? 0 : $this->allocations->apply(
            $company, ['settlement_id' => $settlement->getKey()], $requested, $settlement->settled_on, $actor, $settlement->party_id, $settlement->type->documentType(),
        );

        $settlement->forceFill([
            'status' => SettlementStatus::Posted,
            'number' => $number,
            'journal_id' => $journal->getKey(),
            'allocated_minor' => $allocated,
            'requested_allocations' => null,
            'approved_by' => $approver?->getKey(),
            'posted_at' => now(),
            'version' => $settlement->version + 1,
        ])->save();
        $this->audit->record('accounting.settlement_posted', $settlement, new: $this->auditValues($settlement), actor: $actor, organizationId: $company->getKey());

        return $settlement;
    }

    /**
     * @param  list<array{document_id: string, amount_minor: int}>  $requested
     */
    private function checkAllocations(Organization $company, SettlementType $type, Party $party, int $amount, array $requested): void
    {
        $total = $this->allocations->check($company, $party->getKey(), $type->documentType(), $requested);
        if ($total > $amount) {
            throw ValidationException::withMessages(['allocations' => __('accounting::accounting.validation.allocation_more_than_left')]);
        }
        if ($total < $amount && ! (bool) $this->rules->get('accounting.allow_overpayment', $this->contexts->forOrganization($company))) {
            throw ValidationException::withMessages(['amount_minor' => __('accounting::accounting.validation.unallocated_not_allowed')]);
        }
    }

    private function party(Organization $company, SettlementType $type, string $id): Party
    {
        $party = $this->books->query(Party::class, $company)->whereKey($id)->first();
        $customer = $type === SettlementType::Receipt;
        if ($party === null || ! $party->is_active || ! ($customer ? $party->is_customer : $party->is_vendor)) {
            throw ValidationException::withMessages(['party_id' => __('accounting::accounting.validation.party_'.($customer ? 'customer' : 'vendor'))]);
        }

        return $party;
    }

    /** Money goes into or out of an active asset account (cash, bank, wallet). */
    private function moneyAccount(Organization $company, string $id): Account
    {
        $account = $this->books->query(Account::class, $company)->whereKey($id)->first();
        if ($account === null || ! $account->isPostable() || $account->type !== AccountType::Asset) {
            throw ValidationException::withMessages(['account_id' => __('accounting::accounting.validation.money_account')]);
        }

        return $account;
    }

    private function pending(Organization $company, Settlement $settlement, int $baseVersion, User $actor): Settlement
    {
        $settlement = $this->lock($company, $settlement, $baseVersion);
        if ($settlement->status !== SettlementStatus::PendingApproval) {
            throw AccountingException::notPending();
        }
        if ($actor->getKey() === $settlement->created_by) {
            throw AccountingException::ownJournal();
        }

        return $settlement;
    }

    private function lock(Organization $company, Settlement $settlement, int $baseVersion): Settlement
    {
        /** @var Settlement $fresh */
        $fresh = $this->books->query(Settlement::class, $company)->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw AccountingException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status->value]);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(Settlement $settlement): array
    {
        return [
            'type' => $settlement->type->value, 'number' => $settlement->number, 'party_id' => $settlement->party_id,
            'settled_on' => $settlement->settled_on->toDateString(), 'status' => $settlement->status->value,
            'amount_minor' => $settlement->amount_minor, 'allocated_minor' => $settlement->allocated_minor, 'currency' => $settlement->currency_code,
        ];
    }
}
