<?php

namespace Modules\Payroll\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Component;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\LoanInstallment;
use Modules\Payroll\Models\PfEntry;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\Settlement;
use Modules\Payroll\Models\SettlementLine;

/**
 * Final settlements of people who left (their HRM exit day is set).
 *
 * Calculating works out, with how each was reached: gratuity (rules
 * payroll.gratuity_min_years and payroll.gratuity_days_per_year, on the
 * basic at the last day), the provident fund (the employee's share in full,
 * the company's as far as rule payroll.pf_vesting allows; the rest kept
 * back), and every loan still owed. Lines added by hand (notice pay, leave
 * encashment …) stay; taxable ones carry tax at source as the extra yearly
 * tax on top of regular pay. Approved by someone who neither opened nor sent
 * it: posted, loans closed, the fund paid out. Paid like a salary.
 */
class Settlements
{
    public function __construct(
        private Payrolls $payrolls,
        private Salaries $salaries,
        private Runs $runs,
        private EmployeeDirectory $directory,
        private PayrollPostings $postings,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * People who left (within a year) without a settlement yet.
     *
     * @return list<EmployeeRecord>
     */
    public function due(Organization $company): array
    {
        $settled = $this->payrolls->query(Settlement::class, $company)->pluck('employee_id')->all();
        $since = CarbonImmutable::now()->subYear();

        return array_values(array_filter(
            $this->directory->inUnits($company, $this->payrolls->subtreeIds($company)),
            fn (EmployeeRecord $employee) => $employee->exitsOn !== null && $employee->exitsOn->greaterThanOrEqualTo($since)
                && $employee->exitsOn->lessThanOrEqualTo(CarbonImmutable::now()->addMonths(3)) && ! in_array($employee->id, $settled, true),
        ));
    }

    public function open(Organization $company, EmployeeRecord $employee, User $actor): Settlement
    {
        if ($employee->exitsOn === null) {
            throw PayrollException::notLeaving();
        }

        return $this->payrolls->transaction($company, function () use ($company, $employee, $actor) {
            if ($this->payrolls->query(Settlement::class, $company)->where('employee_id', $employee->id)->exists()) {
                throw PayrollException::settlementExists();
            }
            $settlement = new Settlement;
            $settlement->fill([
                'organization_id' => $company->getKey(), 'employee_id' => $employee->id, 'unit_id' => $employee->unitId,
                'employee_code' => $employee->code, 'employee_name' => $employee->name, 'joined_on' => $employee->joinedOn->toDateString(),
                'left_on' => $employee->exitsOn->toDateString(), 'status' => Settlement::DRAFT, 'currency_code' => $this->payrolls->currency($company),
                'created_by' => $actor->getKey(), 'version' => 1,
            ])->save();
            $this->audit->record('payroll.settlement_opened', $settlement, new: $this->values($settlement), actor: $actor, organizationId: $company->getKey());

            return $this->work($company, $settlement);
        });
    }

    /** Work out the lines again (lines added by hand stay). */
    public function calculate(Organization $company, Settlement $settlement, int $baseVersion, User $actor): Settlement
    {
        return $this->payrolls->transaction($company, function () use ($company, $settlement, $baseVersion, $actor) {
            $settlement = $this->work($company, $this->draft($company, $settlement, $baseVersion));
            $settlement->forceFill(['version' => $settlement->version + 1])->save();
            $this->audit->record('payroll.settlement_calculated', $settlement, new: $this->values($settlement), actor: $actor, organizationId: $company->getKey());

            return $settlement;
        });
    }

    /**
     * A line by hand: an earning (notice pay, leave encashment) or a deduction.
     *
     * @param  array{kind: string, label: string, amount_minor: int, taxable?: bool}  $data
     */
    public function addLine(Organization $company, Settlement $settlement, array $data, User $actor): SettlementLine
    {
        return $this->payrolls->transaction($company, function () use ($company, $settlement, $data, $actor) {
            $settlement = $this->draft($company, $settlement, null);
            $line = new SettlementLine;
            $line->fill([
                'organization_id' => $company->getKey(), 'settlement_id' => $settlement->getKey(), 'line_no' => 0, 'kind' => $data['kind'], 'code' => 'MANUAL',
                'amount_minor' => $data['amount_minor'], 'taxable' => $data['kind'] === 'earning' && ($data['taxable'] ?? true), 'manual' => true, 'created_by' => $actor->getKey(),
            ]);
            $line->putTexts('name', ['en' => $data['label']]);
            $line->save();
            $this->work($company, $settlement);
            $settlement->forceFill(['version' => $settlement->version + 1])->save();
            $this->audit->record('payroll.settlement_line_added', $settlement, new: ['kind' => $data['kind'], 'label' => $data['label'], 'amount_minor' => $data['amount_minor']], actor: $actor, organizationId: $company->getKey());

            return $line;
        });
    }

    public function removeLine(Organization $company, Settlement $settlement, SettlementLine $line, User $actor): void
    {
        $this->payrolls->transaction($company, function () use ($company, $settlement, $line, $actor) {
            $settlement = $this->draft($company, $settlement, null);
            if (! $line->manual) {
                throw PayrollException::settlementLineFixed();
            }
            $this->audit->record('payroll.settlement_line_removed', $settlement, old: ['kind' => $line->kind, 'label' => $line->name, 'amount_minor' => $line->amount_minor], actor: $actor, organizationId: $company->getKey());
            $line->delete();
            $this->work($company, $settlement);
            $settlement->forceFill(['version' => $settlement->version + 1])->save();
        });
    }

    public function submit(Organization $company, Settlement $settlement, int $baseVersion, User $actor): Settlement
    {
        return $this->payrolls->transaction($company, function () use ($company, $settlement, $baseVersion, $actor) {
            $settlement = $this->draft($company, $settlement, $baseVersion);
            if ($settlement->net_minor < 0) {
                throw PayrollException::settlementOwed();
            }
            $settlement->forceFill(['status' => Settlement::PENDING, 'submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'version' => $settlement->version + 1])->save();
            $this->audit->record('payroll.settlement_submitted', $settlement, new: $this->values($settlement), actor: $actor, organizationId: $company->getKey());

            return $settlement;
        });
    }

    /** Approved by someone who neither opened nor sent it: posted, loans closed, the fund paid out. */
    public function approve(Organization $company, Settlement $settlement, int $baseVersion, User $actor): Settlement
    {
        return $this->payrolls->transaction($company, function () use ($company, $settlement, $baseVersion, $actor) {
            $settlement = $this->pending($company, $settlement, $baseVersion, $actor);
            $lines = $this->linesOf($company, $settlement);
            $sum = fn (string $code, ?string $kind = null) => (int) $lines->where('code', $code)->when($kind !== null, fn ($items) => $items->where('kind', $kind))->sum('amount_minor');

            // The fund as it stands now must still match what was worked out.
            [$employeeFund, $employerFund] = $this->fund($company, $settlement->employee_id);
            if ($employeeFund !== $sum('PF_EMPLOYEE') || $employerFund !== $sum('PF_EMPLOYER') + $sum('PF_FORFEIT')) {
                throw PayrollException::settlementStale();
            }
            foreach ($lines->where('code', 'LOAN') as $line) {
                $loan = $this->payrolls->query(Loan::class, $company)->whereKey($line->loan_id)->lockForUpdate()->first();
                if ($loan === null || $loan->status !== Loan::ACTIVE || $loan->balance() !== $line->amount_minor) {
                    throw PayrollException::settlementStale();
                }
                $this->closeLoan($company, $loan, $settlement);
            }

            $manualEarnings = (int) $lines->where('code', 'MANUAL')->where('kind', 'earning')->sum('amount_minor');
            $manualDeductions = (int) $lines->where('code', 'MANUAL')->where('kind', 'deduction')->sum('amount_minor');
            $journal = $this->postings->post($company, "payroll-settlement-{$settlement->getKey()}", CarbonImmutable::now()->startOfDay(),
                __('payroll::payroll.narration.settlement', ['name' => $settlement->employee_name]), 'settlement', $settlement->getKey(), $settlement->currency_code, [
                    LedgerLine::debit('payroll.gratuity_expense', $sum('GRATUITY'), $settlement->unit_id),
                    LedgerLine::debit('payroll.salary_expense', $manualEarnings, $settlement->unit_id),
                    LedgerLine::debit('payroll.pf_payable', $sum('PF_EMPLOYEE') + $sum('PF_EMPLOYER') + $sum('PF_FORFEIT')),
                    LedgerLine::credit('payroll.pf_employer_expense', $sum('PF_FORFEIT'), $settlement->unit_id),
                    LedgerLine::credit('payroll.employee_loans', $sum('LOAN')),
                    LedgerLine::credit('payroll.deductions_payable', $manualDeductions),
                    LedgerLine::credit('payroll.tax_payable', $settlement->tax_minor),
                    LedgerLine::credit('payroll.salaries_payable', $settlement->net_minor),
                ]);

            if ($employeeFund + $employerFund > 0) {
                $entry = fn (string $kind, int $employee, int $employer) => (new PfEntry)->fill([
                    'organization_id' => $company->getKey(), 'employee_id' => $settlement->employee_id, 'unit_id' => $settlement->unit_id, 'kind' => $kind,
                    'employee_minor' => -$employee, 'employer_minor' => -$employer, 'currency_code' => $settlement->currency_code, 'settlement_id' => $settlement->getKey(),
                ])->save();
                $entry(PfEntry::WITHDRAWAL, $sum('PF_EMPLOYEE'), $sum('PF_EMPLOYER'));
                if ($sum('PF_FORFEIT') > 0) {
                    $entry(PfEntry::FORFEIT, 0, $sum('PF_FORFEIT'));
                }
            }

            $settlement->forceFill(['status' => Settlement::APPROVED, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'journal_id' => $journal, 'version' => $settlement->version + 1])->save();
            $this->audit->record('payroll.settlement_approved', $settlement, new: [...$this->values($settlement), 'journal_id' => $journal], actor: $actor, organizationId: $company->getKey());

            return $settlement;
        });
    }

    public function reject(Organization $company, Settlement $settlement, int $baseVersion, string $reason, User $actor): Settlement
    {
        return $this->payrolls->transaction($company, function () use ($company, $settlement, $baseVersion, $reason, $actor) {
            $settlement = $this->pending($company, $settlement, $baseVersion, $actor);
            $settlement->forceFill(['status' => Settlement::DRAFT, 'reject_reason' => $reason, 'submitted_by' => null, 'submitted_at' => null, 'version' => $settlement->version + 1])->save();
            $this->audit->record('payroll.settlement_rejected', $settlement, new: $this->values($settlement), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $settlement;
        });
    }

    public function pay(Organization $company, Settlement $settlement, int $baseVersion, CarbonImmutable $paidOn, User $actor): Settlement
    {
        return $this->payrolls->transaction($company, function () use ($company, $settlement, $baseVersion, $paidOn, $actor) {
            $settlement = $this->lock($company, $settlement, $baseVersion);
            if ($settlement->status !== Settlement::APPROVED) {
                throw PayrollException::notApproved();
            }
            $journal = $this->postings->post($company, "payroll-settlement-pay-{$settlement->getKey()}", $paidOn, __('payroll::payroll.narration.settlement_payment', ['name' => $settlement->employee_name]),
                'settlement_payment', $settlement->getKey(), $settlement->currency_code,
                [LedgerLine::debit('payroll.salaries_payable', $settlement->net_minor), LedgerLine::credit('payroll.payment_account', $settlement->net_minor)]);
            $settlement->forceFill(['status' => Settlement::PAID, 'paid_on' => $paidOn->toDateString(), 'payment_journal_id' => $journal, 'version' => $settlement->version + 1])->save();
            $this->audit->record('payroll.settlement_paid', $settlement, new: ['paid_on' => $paidOn->toDateString(), 'net_minor' => $settlement->net_minor, 'journal_id' => $journal], actor: $actor, organizationId: $company->getKey());

            return $settlement;
        });
    }

    public function delete(Organization $company, Settlement $settlement, int $baseVersion, User $actor): void
    {
        $this->payrolls->transaction($company, function () use ($company, $settlement, $baseVersion, $actor) {
            $settlement = $this->draft($company, $settlement, $baseVersion);
            $this->payrolls->query(SettlementLine::class, $company)->where('settlement_id', $settlement->getKey())->delete();
            $this->audit->record('payroll.settlement_deleted', $settlement, old: $this->values($settlement), actor: $actor, organizationId: $company->getKey());
            $settlement->delete();
        });
    }

    /**
     * An employee's provident fund now: [employee share, company share].
     *
     * @return array{0: int, 1: int}
     */
    public function fund(Organization $company, string $employeeId): array
    {
        $entries = $this->payrolls->query(PfEntry::class, $company)->where('employee_id', $employeeId)->get(['employee_minor', 'employer_minor']);

        return [(int) $entries->sum('employee_minor'), (int) $entries->sum('employer_minor')];
    }

    /** @return Collection<int, SettlementLine> */
    public function linesOf(Organization $company, Settlement $settlement)
    {
        return $this->payrolls->query(SettlementLine::class, $company)->where('settlement_id', $settlement->getKey())->orderBy('line_no')->get();
    }

    /** Rebuild the worked-out lines, keep the manual ones, then tax and totals. */
    private function work(Organization $company, Settlement $settlement): Settlement
    {
        $employee = $this->directory->find($company, $settlement->employee_id) ?? throw PayrollException::employeeNotFound();
        $leftOn = $employee->exitsOn ?? $settlement->left_on;
        $context = $this->contexts->forOrganization(Organization::query()->findOrFail($employee->unitId));
        $months = PayCalculator::serviceMonths($employee->joinedOn->toDateString(), $leftOn->addDay()->toDateString());
        $years = intdiv($months, 12);
        $salary = $this->salaries->on($company, $employee->id, $leftOn);
        $basic = $salary?->basic_minor ?? 0;

        $this->payrolls->query(SettlementLine::class, $company)->where('settlement_id', $settlement->getKey())->where('manual', false)->delete();
        $rows = [];
        $add = function (string $kind, string $code, array $name, int $amount, ?array $basis = null, ?string $loanId = null) use (&$rows) {
            if ($amount > 0) {
                $rows[] = compact('kind', 'code', 'name', 'amount', 'basis', 'loanId');
            }
        };

        $minYears = (int) $this->rules->get('payroll.gratuity_min_years', $context);
        $days = (int) $this->rules->get('payroll.gratuity_days_per_year', $context);
        $add('earning', 'GRATUITY', ['en' => 'Gratuity', 'bn' => 'গ্র্যাচুইটি'], PayCalculator::gratuity($basic, $years, $minYears, $days),
            ['key' => 'gratuity', 'params' => ['years' => $years, 'days' => $days, 'min' => $minYears]]);

        [$employeeFund, $employerFund] = $this->fund($company, $employee->id);
        $steps = array_map(fn (array $step) => [(int) $step['after_years'], PayCalculator::toBasisPoints((string) $step['percent'], 100) ?? 0], (array) $this->rules->get('payroll.pf_vesting', $context));
        usort($steps, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $vestedBp = PayCalculator::vestedShare($years, $steps);
        $vested = PayCalculator::share($employerFund, $vestedBp);
        $add('earning', 'PF_EMPLOYEE', ['en' => 'Provident fund (own share)', 'bn' => 'ভবিষ্য তহবিল (নিজের অংশ)'], $employeeFund, ['key' => 'pf_employee', 'params' => []]);
        $add('earning', 'PF_EMPLOYER', ['en' => 'Provident fund (company share)', 'bn' => 'ভবিষ্য তহবিল (কোম্পানির অংশ)'], $vested, ['key' => 'pf_employer', 'params' => ['percent' => $vestedBp, 'years' => $years]]);
        $add('info', 'PF_FORFEIT', ['en' => 'Company share kept back', 'bn' => 'কোম্পানির ফেরত রাখা অংশ'], $employerFund - $vested, ['key' => 'pf_forfeit', 'params' => ['years' => $years]]);

        foreach ($this->payrolls->query(Loan::class, $company)->where('employee_id', $employee->id)->where('status', Loan::ACTIVE)->orderBy('paid_out_on')->get() as $loan) {
            $add('deduction', 'LOAN', ['en' => 'Loan still owed', 'bn' => 'বাকি ঋণ'], $loan->balance(), ['key' => 'loan', 'params' => ['principal' => $loan->principal_minor, 'paid_out_on' => $loan->paid_out_on->toDateString()]], $loan->getKey());
        }

        $manual = $this->payrolls->query(SettlementLine::class, $company)->where('settlement_id', $settlement->getKey())->where('manual', true)->orderBy('created_at')->get();
        $no = 0;
        foreach ($rows as $row) {
            $line = new SettlementLine;
            $line->fill(['organization_id' => $company->getKey(), 'settlement_id' => $settlement->getKey(), 'line_no' => ++$no, 'kind' => $row['kind'], 'code' => $row['code'],
                'amount_minor' => $row['amount'], 'taxable' => false, 'basis' => $row['basis'], 'manual' => false, 'loan_id' => $row['loanId']]);
            $line->putTexts('name', $row['name']);
            $line->save();
        }
        foreach ($manual as $line) {
            $line->forceFill(['line_no' => ++$no])->save();
        }

        // Tax at source on what is taxable (lines by hand), on top of a year of regular pay.
        $lines = $this->linesOf($company, $settlement);
        $taxable = (int) $lines->where('taxable', true)->sum('amount_minor');
        $tax = 0;
        if ($taxable > 0 && $salary !== null) {
            $components = $this->payrolls->query(Component::class, $company)->get()->keyBy('id');
            $tax = PayCalculator::bonusTax($this->runs->monthlyTaxable($company, $employee, $salary, $components), $taxable, $this->runs->ruleSet($employee)['tax_slabs']);
        }
        $earnings = (int) $lines->where('kind', 'earning')->sum('amount_minor');
        $deductions = (int) $lines->where('kind', 'deduction')->sum('amount_minor');

        $settlement->forceFill([
            'left_on' => $leftOn->toDateString(), 'service_years' => $years, 'service_months' => $months % 12, 'basic_minor' => $basic,
            'earnings_minor' => $earnings, 'deductions_minor' => $deductions, 'tax_minor' => $tax, 'net_minor' => $earnings - $deductions - $tax,
            'calculated_at' => now(), 'reject_reason' => null,
        ])->save();

        return $settlement;
    }

    /** A loan settled in full: drafts that planned its instalments need calculating again. */
    private function closeLoan(Organization $company, Loan $loan, Settlement $settlement): void
    {
        $planned = $this->payrolls->query(LoanInstallment::class, $company)->where('loan_id', $loan->getKey())->where('status', LoanInstallment::PLANNED);
        $runIds = (clone $planned)->pluck('run_id')->filter()->all();
        $planned->delete();
        foreach ($this->payrolls->query(Run::class, $company)->whereIn('id', $runIds)->where('status', Run::DRAFT)->get() as $run) {
            $run->forceFill(['calculated_at' => null, 'version' => $run->version + 1])->save();
        }
        $amount = $loan->balance();
        $loan->forceFill(['recovered_minor' => $loan->principal_minor, 'status' => Loan::CLOSED, 'version' => $loan->version + 1])->save();
        $this->audit->record('payroll.loan_settled', $loan, new: ['amount_minor' => $amount, 'settlement_id' => $settlement->getKey()], organizationId: $company->getKey());
    }

    private function draft(Organization $company, Settlement $settlement, ?int $baseVersion): Settlement
    {
        $settlement = $this->lock($company, $settlement, $baseVersion);
        if ($settlement->status !== Settlement::DRAFT) {
            throw PayrollException::notDraft();
        }

        return $settlement;
    }

    private function pending(Organization $company, Settlement $settlement, int $baseVersion, User $actor): Settlement
    {
        $settlement = $this->lock($company, $settlement, $baseVersion);
        if ($settlement->status !== Settlement::PENDING) {
            throw PayrollException::notPending();
        }
        if (in_array($actor->getKey(), [$settlement->created_by, $settlement->submitted_by], true)) {
            throw PayrollException::ownRun();
        }

        return $settlement;
    }

    private function lock(Organization $company, Settlement $settlement, ?int $baseVersion): Settlement
    {
        /** @var Settlement $fresh */
        $fresh = $this->payrolls->query(Settlement::class, $company)->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw PayrollException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Settlement $settlement): array
    {
        return [...$settlement->only(['employee_id', 'status', 'earnings_minor', 'deductions_minor', 'tax_minor', 'net_minor', 'currency_code']), 'left_on' => $settlement->left_on->toDateString()];
    }
}
