<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;

/**
 * The life of a journal entry:
 *
 *   draft -> submit -> posted
 *                   -> pending_approval -> approve -> posted
 *                                       -> reject  -> rejected (edit, submit again)
 *                                       -> withdraw -> draft
 *   posted -> reverse (a new journal with debits and credits swapped)
 *
 * Business numbers are rules of the company: how far back or ahead entries
 * may be dated, above which amount a second person approves, whether lines
 * need a branch or department. The person who wrote or sent a journal never
 * approves it. Posted journals never change.
 */
class Journals
{
    public function __construct(
        private Books $books,
        private Posting $posting,
        private FiscalCalendar $calendar,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{entry_date: string, narration: string, lines: list<array<string, mixed>>, reverses_id?: string|null}  $data
     * @param  array{module?: string, type?: string, id?: string, op_id?: string|null}  $source  Set by Ledger for other modules' postings.
     */
    public function draft(Organization $company, array $data, ?User $actor, array $source = []): Journal
    {
        $this->books->assertSetUp($company);
        $lines = $this->checkLines($company, $data['lines']);

        return $this->books->transaction($company, function () use ($company, $data, $lines, $actor, $source) {
            $journal = new Journal;
            $journal->fill([
                'organization_id' => $company->getKey(),
                'entry_date' => $data['entry_date'],
                'narration' => $data['narration'],
                'status' => JournalStatus::Draft,
                'currency_code' => $this->books->currency($company),
                'total_minor' => array_sum(array_column($lines, 'debit_minor')),
                'source_module' => $source['module'] ?? null,
                'source_type' => $source['type'] ?? null,
                'source_id' => $source['id'] ?? null,
                'op_id' => $source['op_id'] ?? null,
                'reverses_id' => $data['reverses_id'] ?? null,
                'created_by' => $actor?->getKey(),
                'version' => 1,
            ])->save();
            $this->writeLines($company, $journal, $lines);

            $this->audit->record('accounting.journal_drafted', $journal, new: $this->auditValues($journal), actor: $actor, organizationId: $company->getKey());

            return $journal;
        });
    }

    /**
     * @param  array{entry_date?: string, narration?: string, lines?: list<array<string, mixed>>}  $data
     */
    public function update(Organization $company, Journal $journal, int $baseVersion, array $data, User $actor): Journal
    {
        $lines = isset($data['lines']) ? $this->checkLines($company, $data['lines']) : null;

        return $this->books->transaction($company, function () use ($company, $journal, $baseVersion, $data, $lines, $actor) {
            $journal = $this->lock($company, $journal, $baseVersion);
            if (! $journal->status->isEditable()) {
                throw AccountingException::notEditable();
            }
            $old = $this->auditValues($journal);

            $journal->fill(array_intersect_key($data, array_flip(['entry_date', 'narration'])));
            if ($lines !== null) {
                $this->books->query(JournalLine::class, $company)->where('journal_id', $journal->getKey())->delete();
                $this->writeLines($company, $journal, $lines);
                $journal->total_minor = array_sum(array_column($lines, 'debit_minor'));
            }
            $journal->forceFill(['status' => JournalStatus::Draft, 'version' => $journal->version + 1])->save();

            $this->audit->record('accounting.journal_updated', $journal, old: $old, new: $this->auditValues($journal), actor: $actor, organizationId: $company->getKey());

            return $journal;
        });
    }

    public function delete(Organization $company, Journal $journal, int $baseVersion, User $actor): void
    {
        $this->books->transaction($company, function () use ($company, $journal, $baseVersion, $actor) {
            $journal = $this->lock($company, $journal, $baseVersion);
            if (! $journal->status->isEditable()) {
                throw AccountingException::notEditable();
            }

            $this->audit->record('accounting.journal_deleted', $journal, old: $this->auditValues($journal), actor: $actor, organizationId: $company->getKey());
            $this->books->query(JournalLine::class, $company)->where('journal_id', $journal->getKey())->delete();
            $journal->delete();
        });
    }

    /**
     * Send a draft: posted straight away, or kept for a second person when the
     * amount needs approval. People's entries must also fall inside the dates
     * the company allows; other modules' postings only need an open period.
     */
    public function submit(Organization $company, Journal $journal, ?int $baseVersion, ?User $actor): Journal
    {
        return $this->books->transaction($company, function () use ($company, $journal, $baseVersion, $actor) {
            $journal = $this->lock($company, $journal, $baseVersion);
            if (! $journal->status->isEditable()) {
                throw AccountingException::notEditable();
            }

            $this->assertPostable($company, $journal);
            if ($actor !== null) {
                $this->assertDateAllowed($company, $journal->entry_date);
            }

            if ($this->needsApproval($company, $journal)) {
                // Fail early: the period must exist and be open now as well as at approval.
                $this->calendar->openPeriodFor($company, $journal->entry_date);
                $journal->forceFill([
                    'status' => JournalStatus::PendingApproval,
                    'submitted_by' => $actor?->getKey(),
                    'submitted_at' => now(),
                    'rejected_by' => null,
                    'reject_reason' => null,
                    'version' => $journal->version + 1,
                ])->save();
                $this->audit->record('accounting.journal_submitted', $journal, new: $this->auditValues($journal), actor: $actor, organizationId: $company->getKey());

                return $journal;
            }

            $journal->forceFill(['submitted_by' => $actor?->getKey(), 'submitted_at' => now()]);

            return $this->posting->post($company, $journal, null, $actor);
        });
    }

    public function approve(Organization $company, Journal $journal, int $baseVersion, User $actor): Journal
    {
        return $this->books->transaction($company, function () use ($company, $journal, $baseVersion, $actor) {
            $journal = $this->pending($company, $journal, $baseVersion, $actor);
            $this->assertPostable($company, $journal);

            return $this->posting->post($company, $journal, $actor, $actor);
        });
    }

    public function reject(Organization $company, Journal $journal, int $baseVersion, string $reason, User $actor): Journal
    {
        return $this->books->transaction($company, function () use ($company, $journal, $baseVersion, $reason, $actor) {
            $journal = $this->pending($company, $journal, $baseVersion, $actor);
            $journal->forceFill([
                'status' => JournalStatus::Rejected, 'rejected_by' => $actor->getKey(), 'reject_reason' => $reason, 'version' => $journal->version + 1,
            ])->save();

            $this->audit->record('accounting.journal_rejected', $journal, new: $this->auditValues($journal), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $journal;
        });
    }

    /** The person who sent a journal takes it back to change it. */
    public function withdraw(Organization $company, Journal $journal, int $baseVersion, User $actor): Journal
    {
        return $this->books->transaction($company, function () use ($company, $journal, $baseVersion, $actor) {
            $journal = $this->lock($company, $journal, $baseVersion);
            if ($journal->status !== JournalStatus::PendingApproval) {
                throw AccountingException::notPending();
            }
            if ($journal->submitted_by !== $actor->getKey()) {
                throw AccountingException::notSubmitter();
            }

            $journal->forceFill(['status' => JournalStatus::Draft, 'submitted_by' => null, 'submitted_at' => null, 'version' => $journal->version + 1])->save();
            $this->audit->record('accounting.journal_withdrawn', $journal, new: $this->auditValues($journal), actor: $actor, organizationId: $company->getKey());

            return $journal;
        });
    }

    /**
     * Undo a posted journal with a new one that swaps its debits and credits,
     * dated $entryDate (today by default) and sent like any other journal.
     */
    public function reverse(Organization $company, Journal $journal, ?string $entryDate, string $reason, User $actor, bool $fromSource = false): Journal
    {
        return $this->books->transaction($company, function () use ($company, $journal, $entryDate, $reason, $actor, $fromSource) {
            /** @var Journal $original */
            $original = $this->books->query(Journal::class, $company)->whereKey($journal->getKey())->lockForUpdate()->firstOrFail();
            if ($original->status !== JournalStatus::Posted) {
                throw AccountingException::notPosted();
            }
            // An entry made by an invoice, receipt or another module is undone there (void), so both stay in step.
            if ($original->source_module !== null && ! $fromSource) {
                throw AccountingException::sourcedJournal();
            }
            if ($original->reverses_id !== null) {
                throw AccountingException::reversalOfReversal();
            }
            if ($original->reversed_by_id !== null || $this->books->query(Journal::class, $company)->where('reverses_id', $original->getKey())->exists()) {
                throw AccountingException::alreadyReversed();
            }

            $reversal = $this->draft($company, [
                'entry_date' => $entryDate ?? $this->books->today($company)->toDateString(),
                'narration' => __('accounting::accounting.reversal_narration', ['number' => $original->number, 'reason' => $reason]),
                'reverses_id' => $original->getKey(),
                'lines' => $this->books->linesOf($company, $original)->get()->map(fn (JournalLine $line) => [
                    'account_id' => $line->account_id,
                    'cost_centre_id' => $line->cost_centre_id,
                    'debit_minor' => $line->credit_minor,
                    'credit_minor' => $line->debit_minor,
                    'memo' => $line->memo,
                ])->all(),
            ], $actor, $fromSource ? ['module' => $original->source_module, 'type' => $original->source_type, 'id' => $original->source_id] : []);

            $this->audit->record('accounting.journal_reversed', $original, new: ['reversal_id' => $reversal->getKey()], reason: $reason, actor: $actor, organizationId: $company->getKey());

            // Undoing an approved record posts at once (the void itself is the second person's step).
            return $fromSource
                ? $this->postApproved($company, $reversal, $actor, $actor)
                : $this->submit($company, $reversal, $reversal->version, $actor);
        });
    }

    /**
     * Whether a journal of this amount waits for a second person
     * (rule accounting.journal_approval_above; empty = never). An amount in
     * another currency than the rule's always does: it cannot be compared.
     */
    public function needsApproval(Organization $company, Journal $journal): bool
    {
        return $this->amountNeedsApproval($company, $journal->total_minor, $journal->currency_code);
    }

    /** The same check for any amount (documents and settlements use it too). */
    public function amountNeedsApproval(Organization $company, int $amountMinor, string $currency): bool
    {
        $limit = $this->rules->get('accounting.journal_approval_above', $this->contexts->forOrganization($company));
        if (! is_array($limit)) {
            return false;
        }

        return $limit['currency'] !== $currency || $amountMinor > (int) $limit['amount'];
    }

    /**
     * Post a journal whose record was already approved (or needed no approval):
     * a document or settlement that was checked and approved as a whole.
     * Balanced and usable accounts are still required; no second approval.
     */
    public function postApproved(Organization $company, Journal $journal, ?User $approver, ?User $actor): Journal
    {
        return $this->books->transaction($company, function () use ($company, $journal, $approver, $actor) {
            $journal = $this->lock($company, $journal, null);
            if (! $journal->status->isEditable()) {
                throw AccountingException::notEditable();
            }
            $this->assertPostable($company, $journal);
            $journal->forceFill(['submitted_by' => $actor?->getKey(), 'submitted_at' => now()]);

            return $this->posting->post($company, $journal, $approver, $actor);
        });
    }

    /**
     * Lines as written: each to an active, non-group account of the company,
     * one side only, with a cost centre inside the company (required below
     * the company when the rule says so).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{account_id: string, cost_centre_id: string, debit_minor: int, credit_minor: int, memo: string|null}>
     */
    public function checkLines(Organization $company, array $lines): array
    {
        $accounts = $this->books->query(Account::class, $company)
            ->whereKey(array_values(array_unique(array_filter(array_column($lines, 'account_id'), 'is_string'))))
            ->get()->keyBy('id');
        $needsUnit = (bool) $this->rules->get('accounting.require_cost_centre', $this->contexts->forOrganization($company));

        $errors = [];
        $checked = [];
        foreach (array_values($lines) as $index => $line) {
            $account = $accounts[$line['account_id'] ?? ''] ?? null;
            if ($account === null || ! $account->isPostable()) {
                $errors["lines.{$index}.account_id"] = __('accounting::accounting.validation.account_not_postable');
            }

            $costCentre = $this->books->costCentre($company, $line['cost_centre_id'] ?? null);
            if ($costCentre === null) {
                $errors["lines.{$index}.cost_centre_id"] = __('accounting::accounting.validation.cost_centre_outside');
            } elseif ($needsUnit && $costCentre === $company->getKey()) {
                $errors["lines.{$index}.cost_centre_id"] = __('accounting::accounting.validation.cost_centre_required');
            }

            $debit = (int) ($line['debit_minor'] ?? 0);
            $credit = (int) ($line['credit_minor'] ?? 0);
            if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                $errors["lines.{$index}.debit_minor"] = __('accounting::accounting.validation.one_side');
            }

            $checked[] = [
                'account_id' => (string) ($line['account_id'] ?? ''),
                'cost_centre_id' => (string) $costCentre,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'memo' => $line['memo'] ?? null,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $checked;
    }

    /**
     * Balanced, at least two lines, accounts still usable (they may have been
     * archived since the draft was written).
     */
    private function assertPostable(Organization $company, Journal $journal): void
    {
        $lines = $this->books->linesOf($company, $journal)->get();
        if ($lines->count() < 2) {
            throw AccountingException::tooFewLines();
        }

        $debit = (int) $lines->sum('debit_minor');
        $credit = (int) $lines->sum('credit_minor');
        if ($debit !== $credit || $debit === 0) {
            throw AccountingException::unbalanced($debit, $credit);
        }

        $this->checkLines($company, $lines->map(fn (JournalLine $line) => $line->only(['account_id', 'cost_centre_id', 'debit_minor', 'credit_minor', 'memo']))->all());
    }

    /** People date entries within the company's window (rules on backdating and future dates). */
    public function assertDateAllowed(Organization $company, CarbonImmutable $date): void
    {
        $context = $this->contexts->forOrganization($company);
        $today = $this->books->today($company);
        $day = CarbonImmutable::parse($date->toDateString(), 'UTC');

        $back = (int) $this->rules->get('accounting.allow_backdated_entries_days', $context);
        if ($day->lessThan($today->subDays($back))) {
            throw AccountingException::tooOld($back);
        }

        $ahead = (int) $this->rules->get('accounting.allow_future_entries_days', $context);
        if ($day->greaterThan($today->addDays($ahead))) {
            throw AccountingException::tooFarAhead($ahead);
        }
    }

    /** A journal waiting for approval, and an approver who did not write or send it. */
    private function pending(Organization $company, Journal $journal, int $baseVersion, User $actor): Journal
    {
        $journal = $this->lock($company, $journal, $baseVersion);
        if ($journal->status !== JournalStatus::PendingApproval) {
            throw AccountingException::notPending();
        }
        if (in_array($actor->getKey(), [$journal->created_by, $journal->submitted_by], true)) {
            throw AccountingException::ownJournal();
        }

        return $journal;
    }

    /**
     * @param  list<array{account_id: string, cost_centre_id: string, debit_minor: int, credit_minor: int, memo: string|null}>  $lines
     */
    private function writeLines(Organization $company, Journal $journal, array $lines): void
    {
        foreach ($lines as $index => $line) {
            $row = new JournalLine;
            $row->fill([...$line, 'organization_id' => $company->getKey(), 'journal_id' => $journal->getKey(), 'line_no' => $index + 1])->save();
        }
    }

    /** The row again, locked; stale versions are refused (nobody loses work). */
    private function lock(Organization $company, Journal $journal, ?int $baseVersion): Journal
    {
        /** @var Journal $fresh */
        $fresh = $this->books->query(Journal::class, $company)->whereKey($journal->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw AccountingException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status->value]);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(Journal $journal): array
    {
        return [
            'number' => $journal->number, 'entry_date' => $journal->entry_date->toDateString(), 'status' => $journal->status->value,
            'total_minor' => $journal->total_minor, 'currency' => $journal->currency_code,
        ];
    }
}
