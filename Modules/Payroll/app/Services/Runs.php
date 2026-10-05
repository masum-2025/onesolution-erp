<?php

namespace Modules\Payroll\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Attendance\Services\AttendanceSummary;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Payroll\Events\PayrollApproved;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Adjustment;
use Modules\Payroll\Models\Component;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\LoanInstallment;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunApproval;
use Modules\Payroll\Models\Salary;
use Modules\Payroll\Models\Slip;
use Modules\Payroll\Models\SlipLine;
use Modules\Payroll\Models\StructureItem;

/**
 * A month's payroll from draft to paid.
 *
 * Calculating (again, while a draft) writes a slip for every employee
 * employed in the month: their salary in force at the end of their time
 * in it, the structure's items, Attendance's absences, half days, over time
 * and late minutes (AttendanceSummary), the run's adjustments, tax at
 * source (PayCalculator), and each active loan's instalment (planned for
 * the run, recovered when it is approved). Sending needs a calculation and no slip with a
 * problem. Approving takes payroll.salary_approval_levels different
 * people, never the one who sent it; at the last level the slips freeze
 * and, where the company keeps books (Accounting on), the salaries are
 * posted: expense per branch or department against salaries, tax and
 * deductions payable, loan instalments against the loans. Paying posts salaries payable against the payment
 * account. Every step is audited.
 */
class Runs
{
    public function __construct(
        private Payrolls $payrolls,
        private Salaries $salaries,
        private EmployeeDirectory $directory,
        private AttendanceSummary $attendance,
        private PayrollPostings $postings,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** Open the payroll of a month ("2026-10"). */
    public function open(Organization $company, string $period, User $actor): Run
    {
        $from = CarbonImmutable::parse("{$period}-01", 'UTC');

        return $this->payrolls->transaction($company, function () use ($company, $period, $from, $actor) {
            if ($this->payrolls->query(Run::class, $company)->where('period', $period)->exists()) {
                throw PayrollException::runExists($period);
            }
            $run = new Run;
            $run->fill([
                'organization_id' => $company->getKey(), 'period' => $period, 'period_from' => $from->toDateString(),
                'period_to' => $from->endOfMonth()->toDateString(), 'status' => Run::DRAFT,
                'currency_code' => $this->payrolls->currency($company), 'created_by' => $actor->getKey(), 'version' => 1,
            ])->save();
            $this->audit->record('payroll.run_opened', $run, new: ['period' => $period], actor: $actor, organizationId: $company->getKey());

            return $run;
        });
    }

    /** Work out every slip of a draft run again. */
    public function calculate(Organization $company, Run $run, int $baseVersion, User $actor): Run
    {
        return $this->payrolls->transaction($company, function () use ($company, $run, $baseVersion, $actor) {
            $run = $this->draft($company, $run, $baseVersion);
            $slips = $this->payrolls->query(Slip::class, $company)->where('run_id', $run->getKey());
            $this->payrolls->query(SlipLine::class, $company)->whereIn('slip_id', (clone $slips)->select('id'))->delete();
            $slips->delete();
            $this->plannedOf($company, $run)->delete();

            $employees = array_filter(
                $this->directory->inUnits($company, $this->payrolls->subtreeIds($company)),
                fn (EmployeeRecord $employee) => $this->employedIn($employee, $run),
            );
            $adjustments = $this->payrolls->query(Adjustment::class, $company)->where('run_id', $run->getKey())->get()->groupBy('employee_id');
            $components = $this->payrolls->query(Component::class, $company)->get()->keyBy('id');
            foreach ($employees as $employee) {
                $this->writeSlip($company, $run, $employee, $adjustments->get($employee->id, collect()), $components);
            }

            $this->totals($company, $run);
            $run->forceFill(['calculated_at' => now(), 'reject_reason' => null, 'version' => $run->version + 1])->save();
            $this->audit->record('payroll.run_calculated', $run, new: $this->values($run), actor: $actor, organizationId: $company->getKey());

            return $run;
        });
    }

    /**
     * A one-off addition or deduction for an employee of a draft run (worked out at the next calculation).
     *
     * @param  array{kind: string, label: string, amount_minor: int, taxable?: bool}  $data
     */
    public function adjust(Organization $company, Run $run, EmployeeRecord $employee, array $data, User $actor): Adjustment
    {
        return $this->payrolls->transaction($company, function () use ($company, $run, $employee, $data, $actor) {
            $run = $this->draft($company, $run, null);
            if (! $this->employedIn($employee, $run)) {
                throw ValidationException::withMessages(['employee_id' => __('payroll::payroll.validation.not_in_period')]);
            }
            $adjustment = new Adjustment;
            $adjustment->fill([
                'organization_id' => $company->getKey(), 'run_id' => $run->getKey(), 'employee_id' => $employee->id, 'kind' => $data['kind'],
                'label' => $data['label'], 'amount_minor' => $data['amount_minor'], 'taxable' => $data['kind'] === 'earning' && ($data['taxable'] ?? true), 'created_by' => $actor->getKey(),
            ])->save();
            $run->forceFill(['calculated_at' => null, 'version' => $run->version + 1])->save();
            $this->audit->record('payroll.adjustment_added', $adjustment, new: $adjustment->only(['employee_id', 'kind', 'label', 'amount_minor']), actor: $actor, organizationId: $company->getKey());

            return $adjustment;
        });
    }

    public function removeAdjustment(Organization $company, Adjustment $adjustment, User $actor): void
    {
        $this->payrolls->transaction($company, function () use ($company, $adjustment, $actor) {
            $run = $this->draft($company, $this->payrolls->query(Run::class, $company)->findOrFail($adjustment->run_id), null);
            $this->audit->record('payroll.adjustment_removed', $adjustment, old: $adjustment->only(['employee_id', 'kind', 'label', 'amount_minor']), actor: $actor, organizationId: $company->getKey());
            $adjustment->delete();
            $run->forceFill(['calculated_at' => null, 'version' => $run->version + 1])->save();
        });
    }

    public function submit(Organization $company, Run $run, int $baseVersion, User $actor): Run
    {
        return $this->payrolls->transaction($company, function () use ($company, $run, $baseVersion, $actor) {
            $run = $this->draft($company, $run, $baseVersion);
            if ($run->calculated_at === null) {
                throw PayrollException::notCalculated();
            }
            $problems = $this->payrolls->query(Slip::class, $company)->where('run_id', $run->getKey())->whereNotNull('problem')->count();
            if ($problems > 0) {
                throw PayrollException::slipProblems($problems);
            }
            $run->forceFill(['status' => Run::PENDING, 'submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'version' => $run->version + 1])->save();
            $this->audit->record('payroll.run_submitted', $run, new: $this->values($run), actor: $actor, organizationId: $company->getKey());

            return $run;
        });
    }

    /** One level of approval; the last one freezes the run and posts it. */
    public function approve(Organization $company, Run $run, int $baseVersion, User $actor): Run
    {
        return $this->payrolls->transaction($company, function () use ($company, $run, $baseVersion, $actor) {
            $run = $this->pending($company, $run, $baseVersion, $actor);
            $approvals = $this->payrolls->query(RunApproval::class, $company)->where('run_id', $run->getKey());
            if ((clone $approvals)->where('user_id', $actor->getKey())->exists()) {
                throw PayrollException::alreadyApproved();
            }
            $level = (clone $approvals)->count() + 1;
            (new RunApproval)->fill(['organization_id' => $company->getKey(), 'run_id' => $run->getKey(), 'level' => $level, 'user_id' => $actor->getKey()])->save();
            $needed = (int) $this->rules->get('payroll.salary_approval_levels', $this->contexts->forOrganization($company));
            $this->audit->record('payroll.run_approved_level', $run, new: ['level' => $level, 'of' => $needed], actor: $actor, organizationId: $company->getKey());

            if ($level < $needed) {
                $run->forceFill(['version' => $run->version + 1])->save();

                return $run;
            }

            $this->recoverLoans($company, $run);
            $journal = $this->post($company, $run, "payroll-run-{$run->getKey()}", $run->period_to, $this->salaryLines($company, $run), 'run');
            $run->forceFill(['status' => Run::APPROVED, 'approved_at' => now(), 'journal_id' => $journal, 'version' => $run->version + 1])->save();
            $this->audit->record('payroll.run_approved', $run, new: [...$this->values($run), 'journal_id' => $journal], actor: $actor, organizationId: $company->getKey());
            PayrollApproved::dispatch($run->getKey(), $company->getKey(), $run->period);

            return $run;
        });
    }

    public function reject(Organization $company, Run $run, int $baseVersion, string $reason, User $actor): Run
    {
        return $this->payrolls->transaction($company, function () use ($company, $run, $baseVersion, $reason, $actor) {
            $run = $this->pending($company, $run, $baseVersion, $actor);
            $this->payrolls->query(RunApproval::class, $company)->where('run_id', $run->getKey())->delete();
            $run->forceFill(['status' => Run::DRAFT, 'reject_reason' => $reason, 'submitted_by' => null, 'submitted_at' => null, 'version' => $run->version + 1])->save();
            $this->audit->record('payroll.run_rejected', $run, new: $this->values($run), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $run;
        });
    }

    /** The salaries were paid on a day: posted against the company's payment account. */
    public function pay(Organization $company, Run $run, int $baseVersion, CarbonImmutable $paidOn, User $actor): Run
    {
        return $this->payrolls->transaction($company, function () use ($company, $run, $baseVersion, $paidOn, $actor) {
            $run = $this->lock($company, $run, $baseVersion);
            if ($run->status !== Run::APPROVED) {
                throw PayrollException::notApproved();
            }
            $lines = $run->net_minor > 0 ? [LedgerLine::debit('payroll.salaries_payable', $run->net_minor), LedgerLine::credit('payroll.payment_account', $run->net_minor)] : [];
            $journal = $this->post($company, $run, "payroll-pay-{$run->getKey()}", $paidOn, $lines, 'payment');
            $run->forceFill(['status' => Run::PAID, 'paid_on' => $paidOn->toDateString(), 'payment_journal_id' => $journal, 'version' => $run->version + 1])->save();
            $this->audit->record('payroll.run_paid', $run, new: ['paid_on' => $paidOn->toDateString(), 'net_minor' => $run->net_minor, 'journal_id' => $journal], actor: $actor, organizationId: $company->getKey());

            return $run;
        });
    }

    /** Throw a draft away (nothing was approved or posted). */
    public function delete(Organization $company, Run $run, int $baseVersion, User $actor): void
    {
        $this->payrolls->transaction($company, function () use ($company, $run, $baseVersion, $actor) {
            $run = $this->draft($company, $run, $baseVersion);
            $slips = $this->payrolls->query(Slip::class, $company)->where('run_id', $run->getKey());
            $this->payrolls->query(SlipLine::class, $company)->whereIn('slip_id', (clone $slips)->select('id'))->delete();
            $slips->delete();
            $this->payrolls->query(Adjustment::class, $company)->where('run_id', $run->getKey())->delete();
            $this->plannedOf($company, $run)->delete();
            $this->audit->record('payroll.run_deleted', $run, old: $this->values($run), actor: $actor, organizationId: $company->getKey());
            $run->delete();
        });
    }

    /**
     * @param  Collection<int, Adjustment>  $adjustments
     * @param  Collection<string, Component>  $components
     */
    private function writeSlip(Organization $company, Run $run, EmployeeRecord $employee, Collection $adjustments, Collection $components): void
    {
        $from = $run->period_from->max($employee->joinedOn);
        $to = $employee->exitsOn === null ? $run->period_to : $run->period_to->min($employee->exitsOn);
        $employedDays = (int) $from->diffInDays($to) + 1;
        $salary = $this->salaries->on($company, $employee->id, $to);
        $summary = $this->attendance->forPeriod($company, [$employee->id], $from, $to)[$employee->id] ?? null;

        $slip = new Slip;
        $slip->fill([
            'organization_id' => $company->getKey(), 'run_id' => $run->getKey(), 'unit_id' => $employee->unitId, 'employee_id' => $employee->id,
            'employee_code' => $employee->code, 'employee_name' => $employee->name, 'salary_id' => $salary?->getKey(), 'basic_minor' => $salary?->basic_minor ?? 0,
            'period_days' => (int) $run->period_from->diffInDays($run->period_to) + 1, 'employed_days' => $employedDays,
            'absent_days' => $summary['days']['absent'] ?? 0, 'half_days' => $summary['days']['half_day'] ?? 0,
            'overtime_minutes' => $summary['overtime_minutes'] ?? 0, 'late_minutes' => $summary['late_minutes'] ?? 0,
        ]);
        if ($salary === null) {
            $slip->fill(['problem' => 'no_salary'])->save();

            return;
        }

        $items = $this->itemsFor($company, $salary, $components);
        $loans = $this->planLoans($company, $run, $employee);

        $result = PayCalculator::slip([
            'basic' => $salary->basic_minor, 'items' => $items, 'period_days' => $slip->period_days, 'employed_days' => $employedDays,
            'absent_days' => $slip->absent_days, 'half_days' => $slip->half_days, 'overtime_minutes' => $slip->overtime_minutes, 'late_minutes' => $slip->late_minutes,
            'adjustments' => $adjustments->map(fn (Adjustment $adjustment) => ['kind' => $adjustment->kind, 'label' => $adjustment->label, 'amount' => $adjustment->amount_minor, 'taxable' => $adjustment->taxable])->values()->all(),
            'loans' => $loans,
        ], $this->ruleSet($employee));

        $slip->fill([
            'earnings_minor' => $result['earnings'], 'deductions_minor' => $result['deductions'], 'tax_minor' => $result['tax'],
            'net_minor' => $result['net'], 'problem' => $result['problem'],
        ])->save();
        foreach ($result['lines'] as $index => $line) {
            $row = new SlipLine;
            $row->fill(['organization_id' => $company->getKey(), 'slip_id' => $slip->getKey(), 'line_no' => $index + 1, 'kind' => $line['kind'], 'code' => $line['code'], 'amount_minor' => $line['amount'], 'taxable' => $line['taxable']]);
            $row->putTexts('name', $line['name']);
            $row->save();
        }
    }

    /**
     * The company's rules at the employee's unit, as PayCalculator takes them.
     *
     * @return array{deduct_absence: bool, overtime_multiplier_bp: int, overtime_base: string, monthly_hours: int, late_deduction: bool, tax_slabs: list<array{0: int|null, 1: int}>}
     */
    public function ruleSet(EmployeeRecord $employee): array
    {
        $context = $this->contexts->forOrganization(Organization::query()->findOrFail($employee->unitId));
        $get = fn (string $key) => $this->rules->get($key, $context);

        return [
            'deduct_absence' => (bool) $get('payroll.deduct_absence'),
            'overtime_multiplier_bp' => PayCalculator::toBasisPoints((string) $get('payroll.overtime_multiplier')) ?? 0,
            'overtime_base' => (string) $get('payroll.overtime_base'),
            'monthly_hours' => (int) $get('payroll.monthly_hours'),
            'late_deduction' => (bool) $get('payroll.late_deduction'),
            'tax_slabs' => array_map(fn (array $slab) => [$slab['upto_minor'] ?? null, PayCalculator::toBasisPoints((string) $slab['rate_percent'], 100) ?? 0], (array) $get('payroll.tax_slabs')),
        ];
    }

    /**
     * Salaries into the books: each unit's earnings as salary expense, against net pay, tax and deductions payable.
     *
     * @return list<LedgerLine>
     */
    private function salaryLines(Organization $company, Run $run): array
    {
        $slips = $this->payrolls->query(Slip::class, $company)->where('run_id', $run->getKey())->get(['unit_id', 'earnings_minor']);
        $lines = [];
        foreach ($slips->groupBy('unit_id') as $unitId => $unitSlips) {
            $lines[] = LedgerLine::debit('payroll.salary_expense', (int) $unitSlips->sum('earnings_minor'), (string) $unitId);
        }
        $loans = (int) $this->payrolls->query(LoanInstallment::class, $company)->where('run_id', $run->getKey())->where('status', LoanInstallment::RECOVERED)->sum('amount_minor');
        $lines[] = LedgerLine::credit('payroll.salaries_payable', $run->net_minor);
        $lines[] = LedgerLine::credit('payroll.tax_payable', $run->tax_minor);
        $lines[] = LedgerLine::credit('payroll.deductions_payable', $run->deductions_minor - $loans);
        $lines[] = LedgerLine::credit('payroll.employee_loans', $loans);

        return $lines;
    }

    /**
     * Into the books where the company keeps them; the journal id, or null.
     *
     * @param  list<LedgerLine>  $lines
     */
    private function post(Organization $company, Run $run, string $opId, CarbonImmutable $date, array $lines, string $type): ?string
    {
        return $this->postings->post($company, $opId, $date, __("payroll::payroll.narration.{$type}", ['period' => $run->period]), $type, $run->getKey(), $run->currency_code, $lines);
    }

    /**
     * A salary's structure items, as PayCalculator takes them.
     *
     * @param  Collection<string, Component>  $components
     * @return list<array<string, mixed>>
     */
    public function itemsFor(Organization $company, Salary $salary, Collection $components): array
    {
        return $this->payrolls->query(StructureItem::class, $company)->where('structure_id', $salary->structure_id)->orderBy('sort')->get()
            ->map(function (StructureItem $item) use ($components) {
                $component = $components[$item->component_id];

                return [
                    'code' => $component->code, 'name' => $component->texts('name'), 'kind' => $component->kind, 'taxable' => $component->taxable,
                    'prorated' => $component->prorated, 'calc' => $item->calc, 'amount' => $item->amount_minor, 'rate_bp' => $item->rate_bp,
                ];
            })->values()->all();
    }

    /**
     * This month's instalment of each of the employee's active loans: from
     * its start month, unless the month is held back, never more than what
     * other draft months have not already planned. Planned for the run.
     *
     * @return list<int>
     */
    private function planLoans(Organization $company, Run $run, EmployeeRecord $employee): array
    {
        $amounts = [];
        $loans = $this->payrolls->query(Loan::class, $company)->where('employee_id', $employee->id)->where('status', Loan::ACTIVE)
            ->where('start_period', '<=', $run->period)->orderBy('start_period')->get();
        foreach ($loans as $loan) {
            $rows = $this->payrolls->query(LoanInstallment::class, $company)->where('loan_id', $loan->getKey())->get();
            if ($rows->contains(fn (LoanInstallment $row) => $row->period === $run->period)) {
                continue;
            }
            $left = $loan->balance() - (int) $rows->where('status', LoanInstallment::PLANNED)->sum('amount_minor');
            $amount = min($loan->installment_minor, $left);
            if ($amount <= 0) {
                continue;
            }
            (new LoanInstallment)->fill([
                'organization_id' => $company->getKey(), 'loan_id' => $loan->getKey(), 'period' => $run->period,
                'status' => LoanInstallment::PLANNED, 'amount_minor' => $amount, 'run_id' => $run->getKey(),
            ])->save();
            $amounts[] = $amount;
        }

        return $amounts;
    }

    /** At approval the run's planned instalments are recovered; a loan paid back closes. */
    private function recoverLoans(Organization $company, Run $run): void
    {
        foreach ($this->plannedOf($company, $run)->get() as $row) {
            /** @var Loan $loan */
            $loan = $this->payrolls->query(Loan::class, $company)->whereKey($row->loan_id)->lockForUpdate()->firstOrFail();
            if ($loan->status !== Loan::ACTIVE || $row->amount_minor > $loan->balance()) {
                throw PayrollException::loanChanged();
            }
            $row->forceFill(['status' => LoanInstallment::RECOVERED])->save();
            $loan->forceFill(['recovered_minor' => $loan->recovered_minor + $row->amount_minor, 'version' => $loan->version + 1]);
            if ($loan->balance() === 0) {
                $loan->status = Loan::CLOSED;
            }
            $loan->save();
            if ($loan->status === Loan::CLOSED) {
                $this->audit->record('payroll.loan_closed', $loan, new: ['recovered_minor' => $loan->recovered_minor, 'period' => $run->period], organizationId: $company->getKey());
            }
        }
    }

    /** @return Builder<LoanInstallment> */
    private function plannedOf(Organization $company, Run $run): Builder
    {
        return $this->payrolls->query(LoanInstallment::class, $company)->where('run_id', $run->getKey())->where('status', LoanInstallment::PLANNED);
    }

    private function totals(Organization $company, Run $run): void
    {
        $slips = $this->payrolls->query(Slip::class, $company)->where('run_id', $run->getKey())->whereNull('problem')->get(['earnings_minor', 'deductions_minor', 'tax_minor', 'net_minor']);
        $run->forceFill([
            'employees' => $this->payrolls->query(Slip::class, $company)->where('run_id', $run->getKey())->count(),
            'earnings_minor' => (int) $slips->sum('earnings_minor'), 'deductions_minor' => (int) $slips->sum('deductions_minor'),
            'tax_minor' => (int) $slips->sum('tax_minor'), 'net_minor' => (int) $slips->sum('net_minor'),
        ]);
    }

    private function employedIn(EmployeeRecord $employee, Run $run): bool
    {
        return $employee->joinedOn->lessThanOrEqualTo($run->period_to)
            && ($employee->exitsOn === null || $employee->exitsOn->greaterThanOrEqualTo($run->period_from));
    }

    private function draft(Organization $company, Run $run, ?int $baseVersion): Run
    {
        $run = $this->lock($company, $run, $baseVersion);
        if ($run->status !== Run::DRAFT) {
            throw PayrollException::notDraft();
        }

        return $run;
    }

    private function pending(Organization $company, Run $run, int $baseVersion, User $actor): Run
    {
        $run = $this->lock($company, $run, $baseVersion);
        if ($run->status !== Run::PENDING) {
            throw PayrollException::notPending();
        }
        if (in_array($actor->getKey(), [$run->created_by, $run->submitted_by], true)) {
            throw PayrollException::ownRun();
        }

        return $run;
    }

    private function lock(Organization $company, Run $run, ?int $baseVersion): Run
    {
        /** @var Run $fresh */
        $fresh = $this->payrolls->query(Run::class, $company)->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw PayrollException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Run $run): array
    {
        return $run->only(['period', 'status', 'employees', 'earnings_minor', 'deductions_minor', 'tax_minor', 'net_minor', 'currency_code']);
    }
}
