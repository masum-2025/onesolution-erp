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
use Modules\Payroll\Models\BonusLine;
use Modules\Payroll\Models\BonusRun;
use Modules\Payroll\Models\Component;

/**
 * Festival bonuses, paid apart from the monthly salary.
 *
 * A bonus is for a day (bonus_on) at a share of the basic (rule
 * payroll.bonus_percent_of_basic unless given). Calculating gives every
 * employee employed that day a line: nothing for less service than
 * payroll.bonus_min_service_months, without a salary, or left out by hand;
 * otherwise the share of the basic in force that day (or an amount set by
 * hand). Tax at source (rule payroll.bonus_taxable) is the extra yearly tax
 * the bonus brings on top of twelve months of regular taxable pay. Sent by
 * payroll staff, approved by someone else (posted: payroll.bonus_expense
 * per unit against salaries and tax payable), then paid like a salary.
 */
class Bonuses
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
     * @param  array{title: array<string, string>, bonus_on: string, rate_bp?: int|null}  $data
     */
    public function open(Organization $company, array $data, User $actor): BonusRun
    {
        return $this->payrolls->transaction($company, function () use ($company, $data, $actor) {
            $rate = $data['rate_bp'] ?? PayCalculator::toBasisPoints((string) $this->rules->get('payroll.bonus_percent_of_basic', $this->contexts->forOrganization($company)), 100) ?? 10000;
            $bonus = new BonusRun;
            $bonus->fill([
                'organization_id' => $company->getKey(), 'bonus_on' => $data['bonus_on'], 'rate_bp' => $rate, 'status' => BonusRun::DRAFT,
                'currency_code' => $this->payrolls->currency($company), 'created_by' => $actor->getKey(), 'version' => 1,
            ]);
            $bonus->putTexts('title', $data['title']);
            $bonus->save();
            $this->audit->record('payroll.bonus_opened', $bonus, new: $this->values($bonus), actor: $actor, organizationId: $company->getKey());

            return $bonus;
        });
    }

    /** Work out every line of a draft again; amounts set by hand and people left out stay so. */
    public function calculate(Organization $company, BonusRun $bonus, int $baseVersion, User $actor): BonusRun
    {
        return $this->payrolls->transaction($company, function () use ($company, $bonus, $baseVersion, $actor) {
            $bonus = $this->draft($company, $bonus, $baseVersion);
            $kept = $this->payrolls->query(BonusLine::class, $company)->where('bonus_run_id', $bonus->getKey())->get()->keyBy('employee_id');
            $this->payrolls->query(BonusLine::class, $company)->where('bonus_run_id', $bonus->getKey())->delete();

            $on = $bonus->bonus_on;
            $components = $this->payrolls->query(Component::class, $company)->get()->keyBy('id');
            foreach ($this->directory->inUnits($company, $this->payrolls->subtreeIds($company)) as $employee) {
                if ($employee->joinedOn->greaterThan($on) || ($employee->exitsOn !== null && $employee->exitsOn->lessThan($on))) {
                    continue;
                }
                $line = new BonusLine;
                $line->fill([
                    'organization_id' => $company->getKey(), 'bonus_run_id' => $bonus->getKey(), 'employee_id' => $employee->id, 'unit_id' => $employee->unitId,
                    'employee_code' => $employee->code, 'employee_name' => $employee->name,
                    'override_minor' => $kept[$employee->id]->override_minor ?? null, 'excluded' => (bool) ($kept[$employee->id]->excluded ?? false),
                ]);
                $this->work($company, $bonus, $employee, $line, $components);
                $line->save();
            }

            $this->totals($company, $bonus);
            $bonus->forceFill(['calculated_at' => now(), 'reject_reason' => null, 'version' => $bonus->version + 1])->save();
            $this->audit->record('payroll.bonus_calculated', $bonus, new: $this->values($bonus), actor: $actor, organizationId: $company->getKey());

            return $bonus;
        });
    }

    /**
     * Leave someone out or set their amount by hand (null = worked out); worked out again at once.
     *
     * @param  array{excluded?: bool, override_minor?: int|null}  $data
     */
    public function changeLine(Organization $company, BonusRun $bonus, BonusLine $line, array $data, User $actor): BonusLine
    {
        return $this->payrolls->transaction($company, function () use ($company, $bonus, $line, $data, $actor) {
            $bonus = $this->draft($company, $bonus, null);
            $old = $line->only(['excluded', 'override_minor', 'net_minor']);
            $line->fill(array_intersect_key($data, array_flip(['excluded', 'override_minor'])));
            $employee = $this->directory->find($company, $line->employee_id) ?? throw PayrollException::employeeNotFound();
            $this->work($company, $bonus, $employee, $line, $this->payrolls->query(Component::class, $company)->get()->keyBy('id'));
            $line->save();
            $this->totals($company, $bonus);
            $bonus->forceFill(['version' => $bonus->version + 1])->save();
            $this->audit->record('payroll.bonus_line_changed', $bonus, old: $old, new: [...$line->only(['employee_id', 'excluded', 'override_minor', 'net_minor'])], actor: $actor, organizationId: $company->getKey());

            return $line;
        });
    }

    public function submit(Organization $company, BonusRun $bonus, int $baseVersion, User $actor): BonusRun
    {
        return $this->payrolls->transaction($company, function () use ($company, $bonus, $baseVersion, $actor) {
            $bonus = $this->draft($company, $bonus, $baseVersion);
            if ($bonus->calculated_at === null) {
                throw PayrollException::notCalculated();
            }
            $bonus->forceFill(['status' => BonusRun::PENDING, 'submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'version' => $bonus->version + 1])->save();
            $this->audit->record('payroll.bonus_submitted', $bonus, new: $this->values($bonus), actor: $actor, organizationId: $company->getKey());

            return $bonus;
        });
    }

    /** Approved by someone who neither opened nor sent it: frozen and posted. */
    public function approve(Organization $company, BonusRun $bonus, int $baseVersion, User $actor): BonusRun
    {
        return $this->payrolls->transaction($company, function () use ($company, $bonus, $baseVersion, $actor) {
            $bonus = $this->pending($company, $bonus, $baseVersion, $actor);
            $lines = [];
            $byUnit = $this->payrolls->query(BonusLine::class, $company)->where('bonus_run_id', $bonus->getKey())->where('gross_minor', '>', 0)->get(['unit_id', 'gross_minor'])->groupBy('unit_id');
            foreach ($byUnit as $unitId => $unitLines) {
                $lines[] = LedgerLine::debit('payroll.bonus_expense', (int) $unitLines->sum('gross_minor'), (string) $unitId);
            }
            $lines[] = LedgerLine::credit('payroll.salaries_payable', $bonus->net_minor);
            $lines[] = LedgerLine::credit('payroll.tax_payable', $bonus->tax_minor);
            $journal = $this->postings->post($company, "payroll-bonus-{$bonus->getKey()}", $bonus->bonus_on, $this->narration($bonus, 'bonus'), 'bonus', $bonus->getKey(), $bonus->currency_code, $lines);
            $bonus->forceFill(['status' => BonusRun::APPROVED, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'journal_id' => $journal, 'version' => $bonus->version + 1])->save();
            $this->audit->record('payroll.bonus_approved', $bonus, new: [...$this->values($bonus), 'journal_id' => $journal], actor: $actor, organizationId: $company->getKey());

            return $bonus;
        });
    }

    public function reject(Organization $company, BonusRun $bonus, int $baseVersion, string $reason, User $actor): BonusRun
    {
        return $this->payrolls->transaction($company, function () use ($company, $bonus, $baseVersion, $reason, $actor) {
            $bonus = $this->pending($company, $bonus, $baseVersion, $actor);
            $bonus->forceFill(['status' => BonusRun::DRAFT, 'reject_reason' => $reason, 'submitted_by' => null, 'submitted_at' => null, 'version' => $bonus->version + 1])->save();
            $this->audit->record('payroll.bonus_rejected', $bonus, new: $this->values($bonus), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $bonus;
        });
    }

    public function pay(Organization $company, BonusRun $bonus, int $baseVersion, CarbonImmutable $paidOn, User $actor): BonusRun
    {
        return $this->payrolls->transaction($company, function () use ($company, $bonus, $baseVersion, $paidOn, $actor) {
            $bonus = $this->lock($company, $bonus, $baseVersion);
            if ($bonus->status !== BonusRun::APPROVED) {
                throw PayrollException::notApproved();
            }
            $journal = $this->postings->post($company, "payroll-bonus-pay-{$bonus->getKey()}", $paidOn, $this->narration($bonus, 'bonus_payment'), 'bonus_payment', $bonus->getKey(), $bonus->currency_code,
                [LedgerLine::debit('payroll.salaries_payable', $bonus->net_minor), LedgerLine::credit('payroll.payment_account', $bonus->net_minor)]);
            $bonus->forceFill(['status' => BonusRun::PAID, 'paid_on' => $paidOn->toDateString(), 'payment_journal_id' => $journal, 'version' => $bonus->version + 1])->save();
            $this->audit->record('payroll.bonus_paid', $bonus, new: ['paid_on' => $paidOn->toDateString(), 'net_minor' => $bonus->net_minor, 'journal_id' => $journal], actor: $actor, organizationId: $company->getKey());

            return $bonus;
        });
    }

    public function delete(Organization $company, BonusRun $bonus, int $baseVersion, User $actor): void
    {
        $this->payrolls->transaction($company, function () use ($company, $bonus, $baseVersion, $actor) {
            $bonus = $this->draft($company, $bonus, $baseVersion);
            $this->payrolls->query(BonusLine::class, $company)->where('bonus_run_id', $bonus->getKey())->delete();
            $this->audit->record('payroll.bonus_deleted', $bonus, old: $this->values($bonus), actor: $actor, organizationId: $company->getKey());
            $bonus->delete();
        });
    }

    /**
     * One employee's line: why nothing, or the bonus, its tax and net.
     *
     * @param  Collection<string, Component>  $components
     */
    private function work(Organization $company, BonusRun $bonus, EmployeeRecord $employee, BonusLine $line, $components): void
    {
        $on = $bonus->bonus_on;
        $context = $this->contexts->forOrganization(Organization::query()->findOrFail($employee->unitId));
        $salary = $this->salaries->on($company, $employee->id, $on);
        $months = PayCalculator::serviceMonths($employee->joinedOn->toDateString(), $on->toDateString());
        $line->fill(['basic_minor' => $salary?->basic_minor ?? 0, 'service_months' => $months, 'gross_minor' => 0, 'tax_minor' => 0, 'net_minor' => 0]);

        $reason = match (true) {
            $line->excluded => 'excluded',
            $salary === null => 'no_salary',
            $line->override_minor === null && $months < (int) $this->rules->get('payroll.bonus_min_service_months', $context) => 'short_service',
            default => null,
        };
        $line->not_paid_reason = $reason;
        if ($reason !== null) {
            return;
        }

        $gross = $line->override_minor ?? PayCalculator::share($salary->basic_minor, $bonus->rate_bp);
        $tax = 0;
        if ($gross > 0 && $this->rules->get('payroll.bonus_taxable', $context)) {
            $taxable = $this->runs->monthlyTaxable($company, $employee, $salary, $components);
            $tax = min($gross, PayCalculator::bonusTax($taxable, $gross, $this->runs->ruleSet($employee)['tax_slabs']));
        }
        $line->fill(['gross_minor' => $gross, 'tax_minor' => $tax, 'net_minor' => $gross - $tax]);
    }

    private function totals(Organization $company, BonusRun $bonus): void
    {
        $lines = $this->payrolls->query(BonusLine::class, $company)->where('bonus_run_id', $bonus->getKey())->get(['gross_minor', 'tax_minor', 'net_minor']);
        $bonus->forceFill([
            'employees' => $lines->where('gross_minor', '>', 0)->count(),
            'gross_minor' => (int) $lines->sum('gross_minor'), 'tax_minor' => (int) $lines->sum('tax_minor'), 'net_minor' => (int) $lines->sum('net_minor'),
        ]);
    }

    private function narration(BonusRun $bonus, string $type): string
    {
        return __("payroll::payroll.narration.{$type}", ['title' => $bonus->title, 'date' => $bonus->bonus_on->toDateString()]);
    }

    private function draft(Organization $company, BonusRun $bonus, ?int $baseVersion): BonusRun
    {
        $bonus = $this->lock($company, $bonus, $baseVersion);
        if ($bonus->status !== BonusRun::DRAFT) {
            throw PayrollException::notDraft();
        }

        return $bonus;
    }

    private function pending(Organization $company, BonusRun $bonus, int $baseVersion, User $actor): BonusRun
    {
        $bonus = $this->lock($company, $bonus, $baseVersion);
        if ($bonus->status !== BonusRun::PENDING) {
            throw PayrollException::notPending();
        }
        if (in_array($actor->getKey(), [$bonus->created_by, $bonus->submitted_by], true)) {
            throw PayrollException::ownRun();
        }

        return $bonus;
    }

    private function lock(Organization $company, BonusRun $bonus, ?int $baseVersion): BonusRun
    {
        /** @var BonusRun $fresh */
        $fresh = $this->payrolls->query(BonusRun::class, $company)->whereKey($bonus->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw PayrollException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(BonusRun $bonus): array
    {
        return [...$bonus->only(['status', 'rate_bp', 'employees', 'gross_minor', 'tax_minor', 'net_minor', 'currency_code']), 'bonus_on' => $bonus->bonus_on->toDateString()];
    }
}
