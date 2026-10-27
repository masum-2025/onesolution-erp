<?php

namespace Modules\Expenses\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Expenses\Exceptions\ExpensesException;
use Modules\Expenses\Models\Advance;
use Modules\Expenses\Models\AdvanceApproval;
use Modules\Expenses\Models\AdvanceMove;
use Modules\Expenses\Models\Claim;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;

/**
 * Advances: money an employee asks for before spending it. Asked for (at
 * most expenses.advance_max_open owed or asked at a time), decided step by
 * step like a claim (Deciders), paid in cash or by bank (into the books:
 * employee advances against cash or bank), then settled: by the claims
 * that name it (what the employee is owed goes against the advance), by
 * handing back what is left, or from a month's salary (Payroll's
 * DeductionSource; settled when that month is approved). Expected back by
 * settle_by (paid day + expenses.advance_settle_days unless given). Every
 * movement kept (exp_advance_moves) and audited.
 */
class Advances
{
    public function __construct(
        private Place $place,
        private Deciders $deciders,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private OrganizationSettingsResolver $settings,
        private ModuleResolver $modules,
        private Postings $postings,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * Asked for: sent for approval at once. The same op id is the same advance.
     *
     * @param  array{amount_minor: int, purpose: string, settle_by?: string|null, op_id?: string|null}  $data
     */
    public function request(Organization $company, EmployeeRecord $employee, array $data, User $actor): Advance
    {
        if (! empty($data['op_id'])) {
            $same = $this->place->query(Advance::class, $company)->where('op_id', $data['op_id'])->first();
            if ($same !== null) {
                return $same;
            }
        }
        $context = $this->contexts->forOrganization($company);
        $advance = $this->place->transaction($company, function () use ($company, $employee, $data, $actor, $context) {
            $open = $this->place->query(Advance::class, $company)->where('employee_id', $employee->id)->whereIn('status', Advance::OPEN)->lockForUpdate()->get();
            $owed = (int) $open->sum(fn (Advance $advance) => $advance->status === Advance::PAID ? $this->outstanding($company, $advance) : $advance->amount_minor);
            $max = (int) $this->rules->get('expenses.advance_max_open', $context);
            if ($owed + $data['amount_minor'] > $max) {
                throw ValidationException::withMessages(['amount_minor' => __('expenses::expenses.validation.advance_max', [
                    'max' => $this->money($max, $company), 'owed' => $this->money($owed, $company),
                ])]);
            }
            $advance = new Advance;
            $advance->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $employee->unitId, 'employee_id' => $employee->id, 'number' => $this->nextNumber($company),
                'purpose' => trim($data['purpose']), 'currency' => (string) ($this->settings->values($company)['currency_code'] ?? 'BDT'),
                'amount_minor' => $data['amount_minor'], 'settle_by' => $data['settle_by'] ?? null, 'status' => Advance::SUBMITTED,
                'steps_needed' => $data['amount_minor'] > (int) $this->rules->get('expenses.second_approval_above', $context) ? 2 : 1, 'steps_done' => 0,
                'requested_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null, 'version' => 1,
            ])->save();
            $this->audit->record('expenses.advance_requested', $advance, new: $this->values($advance), actor: $actor, organizationId: $company->getKey());

            return $advance;
        });
        $this->tell('expenses.advance_submitted', $company, $advance,
            $this->deciders->approvers($company, $advance->unit_id, $advance->employee_id, 0)->reject(fn (User $user) => $user->getKey() === $employee->userId || $user->getKey() === $actor->getKey()));

        return $advance;
    }

    /** Taken back by the employee before anyone decided. */
    public function withdraw(Organization $company, Advance $advance, int $baseVersion, User $actor): Advance
    {
        return $this->place->transaction($company, function () use ($company, $advance, $baseVersion, $actor) {
            $advance = $this->fresh($company, $advance, $baseVersion);
            if ($advance->status !== Advance::SUBMITTED || $advance->steps_done > 0) {
                throw ExpensesException::notPayable();
            }
            $advance->forceFill(['status' => Advance::WITHDRAWN, 'version' => $advance->version + 1])->save();
            $this->audit->record('expenses.advance_withdrawn', $advance, new: $this->values($advance), actor: $actor, organizationId: $company->getKey());

            return $advance;
        });
    }

    public function canDecide(Organization $company, Advance $advance, User $actor): bool
    {
        if ($advance->status !== Advance::SUBMITTED) {
            return false;
        }
        $decided = $this->place->query(AdvanceApproval::class, $company)->where('advance_id', $advance->getKey())->pluck('user_id')->map(fn ($id) => (string) $id)->all();

        return $this->deciders->canDecide($company, $advance->unit_id, $advance->employee_id, $advance->requested_by, $advance->steps_done, $decided, $actor);
    }

    /** approve | reject (a note needed) */
    public function decide(Organization $company, Advance $advance, string $step, int $baseVersion, ?string $note, User $actor): Advance
    {
        [$advance, $tell] = $this->place->transaction($company, function () use ($company, $advance, $step, $baseVersion, $note, $actor) {
            $advance = $this->fresh($company, $advance, $baseVersion);
            if (! $this->canDecide($company, $advance, $actor)) {
                throw ExpensesException::cannotDecide();
            }
            $number = $advance->steps_done + 1;
            (new AdvanceApproval)->fill(['organization_id' => $company->getKey(), 'advance_id' => $advance->getKey(), 'step' => $number, 'user_id' => $actor->getKey(),
                'decision' => $step === 'approve' ? 'approved' : 'rejected', 'note' => $note])->save();
            if ($step === 'reject') {
                $advance->forceFill(['status' => Advance::REJECTED, 'decided_at' => now(), 'decided_by' => $actor->getKey(), 'decision_note' => $note]);
                $tell = 'employee';
            } elseif ($number >= $advance->steps_needed) {
                $advance->forceFill(['status' => Advance::APPROVED, 'steps_done' => $number, 'decided_at' => now(), 'decided_by' => $actor->getKey(), 'decision_note' => $note]);
                $tell = 'employee';
            } else {
                $advance->forceFill(['steps_done' => $number]);
                $tell = 'next';
            }
            $advance->forceFill(['version' => $advance->version + 1])->save();
            $this->audit->record($step === 'approve' ? 'expenses.advance_approved' : 'expenses.advance_rejected', $advance, new: [...$this->values($advance), 'step' => $number], reason: $note, actor: $actor, organizationId: $company->getKey());

            return [$advance, $tell];
        });
        if ($tell === 'next') {
            $this->tell('expenses.advance_submitted', $company, $advance,
                $this->deciders->approvers($company, $advance->unit_id, $advance->employee_id, $advance->steps_done)->reject(fn (User $user) => $user->getKey() === $actor->getKey()));
        } else {
            $this->tell('expenses.advance_decided', $company, $advance, $this->employeeUsers($company, $advance), $note);
        }

        return $advance;
    }

    /**
     * Paid out in cash or by bank (into the books: employee advances against cash or bank); expected back by its
     * settle_by, or expenses.advance_settle_days from today.
     *
     * @param  array{method: string, base_version: int}  $data
     */
    public function pay(Organization $company, Advance $advance, array $data, User $actor): Advance
    {
        $advance = $this->place->transaction($company, function () use ($company, $advance, $data, $actor) {
            $advance = $this->fresh($company, $advance, (int) $data['base_version']);
            if ($advance->status !== Advance::APPROVED) {
                throw ExpensesException::notPayable();
            }
            $today = $this->place->today($company);
            $account = $data['method'] === 'bank' ? 'expenses.bank_account' : 'expenses.cash_account';
            $journal = $this->postings->post($company, "expense-advance-{$advance->getKey()}", $today,
                __('expenses::expenses.narration.advance', ['number' => $advance->number]), 'advance', $advance->getKey(), $advance->currency,
                [LedgerLine::debit('expenses.employee_advances', $advance->amount_minor), LedgerLine::credit($account, $advance->amount_minor)]);
            $days = (int) $this->rules->get('expenses.advance_settle_days', $this->contexts->forOrganization($company));
            $advance->forceFill([
                'status' => Advance::PAID, 'pay_method' => $data['method'], 'paid_at' => now(), 'paid_by' => $actor->getKey(), 'journal_id' => $journal,
                'settle_by' => $advance->settle_by ?? $today->addDays($days), 'version' => $advance->version + 1,
            ])->save();
            $this->move($company, $advance, AdvanceMove::PAID, $advance->amount_minor, $actor, ['method' => $data['method'], 'journal_id' => $journal]);
            $this->audit->record('expenses.advance_paid', $advance, new: [...$this->values($advance), 'journal_id' => $journal], actor: $actor, organizationId: $company->getKey());

            return $advance;
        });
        $this->tell('expenses.advance_decided', $company, $advance, $this->employeeUsers($company, $advance));

        return $advance;
    }

    /**
     * A claim naming the advance is being paid: what the employee is owed goes against the advance, as far as it
     * goes (into the books: claims payable against employee advances). How much of the claim it took.
     */
    public function settleByClaim(Organization $company, Claim $claim, User $actor): int
    {
        /** @var Advance|null $advance */
        $advance = $this->place->query(Advance::class, $company)->whereKey($claim->advance_id)->lockForUpdate()->first();
        if ($advance === null || $advance->status !== Advance::PAID) {
            return 0;
        }
        $offset = min($claim->total_minor, $this->outstanding($company, $advance));
        if ($offset <= 0) {
            return 0;
        }
        $journal = $this->postings->post($company, "expense-claim-advance-{$claim->getKey()}", $this->place->today($company),
            __('expenses::expenses.narration.claim_advance', ['number' => $claim->number, 'advance' => $advance->number]), 'claim', $claim->getKey(), $advance->currency,
            [LedgerLine::debit('expenses.claims_payable', $offset), LedgerLine::credit('expenses.employee_advances', $offset)]);
        $this->move($company, $advance, AdvanceMove::CLAIM, $offset, $actor, ['claim_id' => $claim->getKey(), 'journal_id' => $journal]);
        $this->settleIfDone($company, $advance, $actor);

        return $offset;
    }

    /**
     * What is left of a paid advance comes back: handed back in cash or by bank now (into the books: cash or bank
     * against employee advances), or from a month's salary (this month or later; settled when Payroll approves it).
     *
     * @param  array{method: string, period?: string|null, base_version: int}  $data
     */
    public function settleRest(Organization $company, Advance $advance, array $data, User $actor): Advance
    {
        return $this->place->transaction($company, function () use ($company, $advance, $data, $actor) {
            $advance = $this->fresh($company, $advance, (int) $data['base_version']);
            $left = $advance->status === Advance::PAID ? $this->outstanding($company, $advance) : 0;
            if ($left <= 0) {
                throw ExpensesException::notPayable();
            }
            if ($data['method'] === 'payroll') {
                if (! $this->modules->isEnabled('payroll', $company)) {
                    throw ExpensesException::payrollOff();
                }
                $period = (string) ($data['period'] ?? '');
                if ($period < $this->place->today($company)->format('Y-m')) {
                    throw ValidationException::withMessages(['period' => __('expenses::expenses.validation.period_past')]);
                }
                $advance->forceFill(['recover_period' => $period, 'version' => $advance->version + 1])->save();
                $this->audit->record('expenses.advance_to_salary', $advance, new: [...$this->values($advance), 'period' => $period, 'left' => $left], actor: $actor, organizationId: $company->getKey());

                return $advance;
            }
            $account = $data['method'] === 'bank' ? 'expenses.bank_account' : 'expenses.cash_account';
            $journal = $this->postings->post($company, "expense-advance-return-{$advance->getKey()}-{$advance->version}", $this->place->today($company),
                __('expenses::expenses.narration.advance_back', ['number' => $advance->number]), 'advance', $advance->getKey(), $advance->currency,
                [LedgerLine::debit($account, $left), LedgerLine::credit('expenses.employee_advances', $left)]);
            $this->move($company, $advance, AdvanceMove::RETURNED, $left, $actor, ['method' => $data['method'], 'journal_id' => $journal]);
            $advance->forceFill(['recover_period' => null, 'version' => $advance->version + 1])->save();
            $this->settleIfDone($company, $advance, $actor);
            $this->audit->record('expenses.advance_returned', $advance, new: [...$this->values($advance), 'returned' => $left], actor: $actor, organizationId: $company->getKey());

            return $advance;
        });
    }

    /**
     * What is left of advances to recover from a month's salary, per employee (Payroll takes it off the slips).
     *
     * @param  list<string>  $employeeIds
     * @return array<string, int>
     */
    public function dueFromSalary(Organization $company, array $employeeIds, string $period): array
    {
        $due = [];
        foreach ($this->place->query(Advance::class, $company)->where('status', Advance::PAID)->where('recover_period', $period)->whereIn('employee_id', $employeeIds)->get() as $advance) {
            $left = $this->outstanding($company, $advance);
            if ($left > 0) {
                $due[$advance->employee_id] = ($due[$advance->employee_id] ?? 0) + $left;
            }
        }

        return $due;
    }

    /** Payroll approved a month: the advances to be recovered with it are settled (Payroll's books took them). How many. */
    public function recoveredWithPayroll(Organization $company, string $period, string $runId): int
    {
        $advances = $this->place->query(Advance::class, $company)->where('status', Advance::PAID)->where('recover_period', $period)->get();
        foreach ($advances as $advance) {
            $left = $this->outstanding($company, $advance);
            if ($left > 0) {
                $this->move($company, $advance, AdvanceMove::SALARY, $left, null, ['run_id' => $runId]);
            }
            $this->settleIfDone($company, $advance, null);
        }

        return $advances->count();
    }

    /** What the employee still owes of a paid advance. */
    public function outstanding(Organization $company, Advance $advance): int
    {
        if (! in_array($advance->status, [Advance::PAID, Advance::SETTLED], true)) {
            return 0;
        }
        $settled = (int) $this->place->query(AdvanceMove::class, $company)->where('advance_id', $advance->getKey())->whereIn('kind', AdvanceMove::SETTLING)->sum('amount_minor');

        return max(0, $advance->amount_minor - $settled);
    }

    /** One of the employee's own paid advances with something left, or a validation error on "advance_id". */
    public function openFor(Organization $company, string $employeeId, string $advanceId): Advance
    {
        $advance = $this->place->query(Advance::class, $company)->whereKey($advanceId)->where('employee_id', $employeeId)->where('status', Advance::PAID)->first();
        if ($advance === null || $this->outstanding($company, $advance) <= 0) {
            throw ValidationException::withMessages(['advance_id' => __('expenses::expenses.validation.advance_open')]);
        }

        return $advance;
    }

    /**
     * Paid advances past their day to settle with something left, at the units.
     *
     * @param  list<string>  $unitIds
     * @return Collection<int, Advance>
     */
    public function overdue(Organization $company, array $unitIds): Collection
    {
        return $this->place->query(Advance::class, $company)->where('status', Advance::PAID)->whereIn('unit_id', $unitIds)
            ->where('settle_by', '<', $this->place->today($company)->toDateString())->get()
            ->filter(fn (Advance $advance) => $this->outstanding($company, $advance) > 0)->values();
    }

    private function settleIfDone(Organization $company, Advance $advance, ?User $actor): void
    {
        if ($advance->status === Advance::PAID && $this->outstanding($company, $advance) === 0) {
            $advance->forceFill(['status' => Advance::SETTLED, 'settled_at' => now(), 'recover_period' => null, 'version' => $advance->version + 1])->save();
            $this->audit->record('expenses.advance_settled', $advance, new: $this->values($advance), actor: $actor, organizationId: $company->getKey());
        }
    }

    /** @param array<string, mixed> $extra */
    private function move(Organization $company, Advance $advance, string $kind, int $amount, ?User $actor, array $extra = []): void
    {
        (new AdvanceMove)->fill([
            'organization_id' => $company->getKey(), 'advance_id' => $advance->getKey(), 'kind' => $kind, 'amount_minor' => $amount, 'user_id' => $actor?->getKey(), ...$extra,
        ])->save();
    }

    /** "ADV-2026-00001": per company and year, the next free number. */
    private function nextNumber(Organization $company): string
    {
        $prefix = 'ADV-'.$this->place->today($company)->format('Y').'-';
        $last = $this->place->query(Advance::class, $company)->where('number', 'like', $prefix.'%')->lockForUpdate()->pluck('number')->max();

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }

    private function fresh(Organization $company, Advance $advance, int $baseVersion): Advance
    {
        /** @var Advance $fresh */
        $fresh = $this->place->query(Advance::class, $company)->whereKey($advance->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw ExpensesException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    /** "5,000.00 BDT" (integer maths). */
    private function money(int $minor, Organization $company): string
    {
        return number_format(intdiv($minor, 100)).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT).' '.($this->settings->values($company)['currency_code'] ?? 'BDT');
    }

    /** @return Collection<int, User> */
    private function employeeUsers(Organization $company, Advance $advance): Collection
    {
        $userId = $this->directory->find($company, $advance->employee_id)?->userId;

        return $userId === null ? collect() : User::query()->whereKey($userId)->get();
    }

    private function tell(string $key, Organization $company, Advance $advance, Collection $to, ?string $note = null): void
    {
        if ($to->isEmpty()) {
            return;
        }
        $employee = $this->directory->find($company, $advance->employee_id);
        $this->notifier->notify($key, $to->values(), fn (string $locale) => [
            'organization' => $company->displayName($locale), 'employee' => (string) $employee?->name, 'number' => (string) $advance->number,
            'amount' => $this->money($advance->amount_minor, $company), 'decision' => __("expenses::expenses.advance_status.{$advance->status}", [], $locale),
            'note' => (string) $note,
        ], $company->partner, $company);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Advance $advance): array
    {
        return [
            ...$advance->only(['number', 'employee_id', 'amount_minor', 'currency', 'status', 'steps_needed', 'steps_done', 'pay_method', 'recover_period']),
            'settle_by' => $advance->settle_by?->toDateString(),
        ];
    }
}
