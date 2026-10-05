<?php

namespace Modules\Payroll\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\LoanInstallment;
use Modules\Payroll\Models\Run;

/**
 * Loans and advances to employees.
 *
 * Asked for by payroll staff (payroll.run): an amount, the number of
 * instalments (rule payroll.loan_max_installments) and, where the rule
 * payroll.loan_max_basic_multiple is above zero, never more than that many
 * months of the basic. Another person approves (payroll.approve): the loan
 * is then paid out and, where the company keeps books, posted to
 * payroll.employee_loans against the payment account. Monthly payroll runs
 * recover it (see Runs); a month can be held back with a reason while that
 * month's payroll is still a draft. Every step is audited.
 */
class Loans
{
    public function __construct(
        private Payrolls $payrolls,
        private Salaries $salaries,
        private PayrollPostings $postings,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{kind: string, principal_minor: int, installments: int, start_period: string, paid_out_on: string, reason?: string|null}  $data
     */
    public function request(Organization $company, EmployeeRecord $employee, array $data, User $actor): Loan
    {
        $context = $this->contexts->forOrganization(Organization::query()->findOrFail($employee->unitId));
        $maxInstallments = (int) $this->rules->get('payroll.loan_max_installments', $context);
        if ($data['installments'] > $maxInstallments) {
            throw ValidationException::withMessages(['installments' => __('payroll::payroll.validation.too_many_installments', ['max' => $maxInstallments])]);
        }
        $paidOut = CarbonImmutable::parse($data['paid_out_on'], 'UTC');
        if ($data['start_period'] < $paidOut->format('Y-m')) {
            throw ValidationException::withMessages(['start_period' => __('payroll::payroll.validation.start_before_paid_out')]);
        }
        if ($employee->exitsOn !== null && $data['start_period'] > $employee->exitsOn->format('Y-m')) {
            throw ValidationException::withMessages(['start_period' => __('payroll::payroll.validation.after_exit')]);
        }
        $multiple = (int) $this->rules->get('payroll.loan_max_basic_multiple', $context);
        if ($multiple > 0) {
            $salary = $this->salaries->on($company, $employee->id, $paidOut);
            if ($salary === null) {
                throw ValidationException::withMessages(['employee_id' => __('payroll::payroll.validation.no_salary_for_loan')]);
            }
            if ($data['principal_minor'] > $salary->basic_minor * $multiple) {
                throw ValidationException::withMessages(['principal_minor' => __('payroll::payroll.validation.loan_too_big', ['months' => $multiple])]);
            }
        }

        return $this->payrolls->transaction($company, function () use ($company, $employee, $data, $actor) {
            $loan = new Loan;
            $loan->fill([
                'organization_id' => $company->getKey(), 'employee_id' => $employee->id, 'unit_id' => $employee->unitId,
                'employee_code' => $employee->code, 'employee_name' => $employee->name, 'kind' => $data['kind'],
                'principal_minor' => $data['principal_minor'], 'installments' => $data['installments'],
                'installment_minor' => PayCalculator::installment($data['principal_minor'], $data['installments']),
                'start_period' => $data['start_period'], 'paid_out_on' => $data['paid_out_on'], 'reason' => $data['reason'] ?? null,
                'status' => Loan::PENDING, 'currency_code' => $this->payrolls->currency($company), 'created_by' => $actor->getKey(), 'version' => 1,
            ])->save();
            $this->audit->record('payroll.loan_requested', $loan, new: $this->values($loan), actor: $actor, organizationId: $company->getKey());

            return $loan;
        });
    }

    /** Approved by someone other than who asked: paid out and posted; drafts from its first month need calculating again. */
    public function approve(Organization $company, Loan $loan, int $baseVersion, ?string $note, User $actor): Loan
    {
        return $this->payrolls->transaction($company, function () use ($company, $loan, $baseVersion, $note, $actor) {
            $loan = $this->pending($company, $loan, $baseVersion, $actor);
            $journal = $this->postings->post($company, "payroll-loan-{$loan->getKey()}", $loan->paid_out_on,
                __('payroll::payroll.narration.loan', ['name' => $loan->employee_name]), 'loan', $loan->getKey(), $loan->currency_code,
                [LedgerLine::debit('payroll.employee_loans', $loan->principal_minor, $loan->unit_id), LedgerLine::credit('payroll.payment_account', $loan->principal_minor)]);
            $loan->forceFill(['status' => Loan::ACTIVE, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note, 'journal_id' => $journal, 'version' => $loan->version + 1])->save();
            $this->staleDrafts($company, $loan->start_period);
            $this->audit->record('payroll.loan_approved', $loan, new: [...$this->values($loan), 'journal_id' => $journal], reason: $note, actor: $actor, organizationId: $company->getKey());

            return $loan;
        });
    }

    public function reject(Organization $company, Loan $loan, int $baseVersion, string $reason, User $actor): Loan
    {
        return $this->payrolls->transaction($company, function () use ($company, $loan, $baseVersion, $reason, $actor) {
            $loan = $this->pending($company, $loan, $baseVersion, $actor);
            $loan->forceFill(['status' => Loan::REJECTED, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $reason, 'version' => $loan->version + 1])->save();
            $this->audit->record('payroll.loan_rejected', $loan, new: $this->values($loan), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $loan;
        });
    }

    /** Withdrawn before anyone decided (payroll staff). */
    public function cancel(Organization $company, Loan $loan, int $baseVersion, User $actor): Loan
    {
        return $this->payrolls->transaction($company, function () use ($company, $loan, $baseVersion, $actor) {
            $loan = $this->lock($company, $loan, $baseVersion);
            if ($loan->status !== Loan::PENDING) {
                throw PayrollException::loanNotPending();
            }
            $loan->forceFill(['status' => Loan::CANCELLED, 'version' => $loan->version + 1])->save();
            $this->audit->record('payroll.loan_cancelled', $loan, new: $this->values($loan), actor: $actor, organizationId: $company->getKey());

            return $loan;
        });
    }

    /** Hold a month's instalment back (the loan simply takes a month longer). */
    public function skip(Organization $company, Loan $loan, string $period, string $reason, User $actor): LoanInstallment
    {
        return $this->payrolls->transaction($company, function () use ($company, $loan, $period, $reason, $actor) {
            $loan = $this->lock($company, $loan, null);
            if ($loan->status !== Loan::ACTIVE) {
                throw PayrollException::loanNotActive();
            }
            $this->openMonth($company, $period);
            $existing = $this->payrolls->query(LoanInstallment::class, $company)->where('loan_id', $loan->getKey())->where('period', $period)->first();
            if ($existing !== null && $existing->status !== LoanInstallment::PLANNED) {
                throw PayrollException::monthLocked($period);
            }
            $existing?->delete();
            $row = new LoanInstallment;
            $row->fill(['organization_id' => $company->getKey(), 'loan_id' => $loan->getKey(), 'period' => $period, 'status' => LoanInstallment::SKIPPED,
                'amount_minor' => 0, 'reason' => $reason, 'created_by' => $actor->getKey()])->save();
            $loan->forceFill(['version' => $loan->version + 1])->save();
            $this->staleDrafts($company, $period, $period);
            $this->audit->record('payroll.loan_month_skipped', $loan, new: ['period' => $period], reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $row;
        });
    }

    /** Take a held-back month off again (its payroll must still be open). */
    public function unskip(Organization $company, Loan $loan, string $period, User $actor): void
    {
        $this->payrolls->transaction($company, function () use ($company, $loan, $period, $actor) {
            $loan = $this->lock($company, $loan, null);
            $this->openMonth($company, $period);
            $row = $this->payrolls->query(LoanInstallment::class, $company)->where('loan_id', $loan->getKey())->where('period', $period)
                ->where('status', LoanInstallment::SKIPPED)->first() ?? throw PayrollException::loanNotFound();
            $row->delete();
            $loan->forceFill(['version' => $loan->version + 1])->save();
            $this->staleDrafts($company, $period, $period);
            $this->audit->record('payroll.loan_skip_removed', $loan, old: ['period' => $period], actor: $actor, organizationId: $company->getKey());
        });
    }

    /**
     * What each month did or will do: recovered, planned, held back, and
     * the months still to come at the instalment (the last one smaller).
     *
     * @return list<array{period: string, status: string, amount_minor: int, reason: string|null}>
     */
    public function schedule(Organization $company, Loan $loan): array
    {
        $rows = $this->payrolls->query(LoanInstallment::class, $company)->where('loan_id', $loan->getKey())->orderBy('period')->get();
        $schedule = $rows->map(fn (LoanInstallment $row) => ['period' => $row->period, 'status' => $row->status, 'amount_minor' => $row->amount_minor, 'reason' => $row->reason])->all();
        if (! in_array($loan->status, [Loan::PENDING, Loan::ACTIVE], true)) {
            return $schedule;
        }

        $left = $loan->balance() - (int) $rows->where('status', LoanInstallment::PLANNED)->sum('amount_minor');
        $taken = $rows->pluck('period')->all();
        $month = CarbonImmutable::parse("{$loan->start_period}-01", 'UTC');
        for ($guard = 0; $left > 0 && $guard < 600; $guard++, $month = $month->addMonthNoOverflow()) {
            $period = $month->format('Y-m');
            if (in_array($period, $taken, true)) {
                continue;
            }
            $amount = min($loan->installment_minor, $left);
            $schedule[] = ['period' => $period, 'status' => 'due', 'amount_minor' => $amount, 'reason' => null];
            $left -= $amount;
        }
        usort($schedule, fn (array $a, array $b) => strcmp($a['period'], $b['period']));

        return $schedule;
    }

    /** A month whose payroll is sent, approved or paid cannot change. */
    private function openMonth(Organization $company, string $period): void
    {
        $run = $this->payrolls->query(Run::class, $company)->where('period', $period)->first();
        if ($run !== null && $run->status !== Run::DRAFT) {
            throw PayrollException::monthLocked($period);
        }
    }

    /** Draft runs of these months need calculating again. */
    private function staleDrafts(Organization $company, string $from, ?string $to = null): void
    {
        $query = $this->payrolls->query(Run::class, $company)->where('status', Run::DRAFT)->where('period', '>=', $from)->whereNotNull('calculated_at');
        if ($to !== null) {
            $query->where('period', '<=', $to);
        }
        foreach ($query->get() as $run) {
            $run->forceFill(['calculated_at' => null, 'version' => $run->version + 1])->save();
        }
    }

    private function pending(Organization $company, Loan $loan, int $baseVersion, User $actor): Loan
    {
        $loan = $this->lock($company, $loan, $baseVersion);
        if ($loan->status !== Loan::PENDING) {
            throw PayrollException::loanNotPending();
        }
        if ($loan->created_by === $actor->getKey()) {
            throw PayrollException::ownLoan();
        }

        return $loan;
    }

    private function lock(Organization $company, Loan $loan, ?int $baseVersion): Loan
    {
        /** @var Loan $fresh */
        $fresh = $this->payrolls->query(Loan::class, $company)->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw PayrollException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Loan $loan): array
    {
        return [...$loan->only(['employee_id', 'kind', 'principal_minor', 'installments', 'installment_minor', 'start_period', 'status', 'currency_code']), 'paid_out_on' => $loan->paid_out_on->toDateString()];
    }
}
