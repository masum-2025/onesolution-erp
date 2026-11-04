<?php

namespace Modules\Payroll\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Component;
use Modules\Payroll\Models\IncrementLine;
use Modules\Payroll\Models\IncrementRound;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\Salary;
use Modules\Payroll\Models\Slip;

/**
 * Increment arrears: what a raise from a past day adds to months already
 * paid (their payroll approved or paid).
 *
 * - Allowed by rule payroll.increment_arrears, at most
 *   payroll.increment_arrears_max_months back; off, a round must start
 *   after the last month sent or approved (as before).
 * - Each month is worked out from its own payslip's facts (days employed,
 *   absences, half days, leave without pay), at the old and the new basic,
 *   with the salary structure's earnings only (no over time, cuts or
 *   one-off lines); the difference is the arrears. A first month the raise
 *   starts in the middle of counts from that day.
 * - Its taxable part and its basic part (for the provident fund) are kept,
 *   so the slip that pays it taxes it as a one-off payment and takes the
 *   fund on it (payroll.arrears_pf).
 * - A month whose payroll is waiting for approval stops the round being
 *   applied until it is decided (its pay is not final yet).
 */
class Arrears
{
    public function __construct(
        private Payrolls $payrolls,
        private Runs $runs,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function enabled(Organization $company): bool
    {
        return (bool) $this->rules->get('payroll.increment_arrears', $this->contexts->forOrganization($company));
    }

    /**
     * Whether a round may start on this day: after the months sent or approved,
     * or (arrears on) within the months allowed back; $applying also refuses
     * a month waiting for approval.
     */
    public function checkDay(Organization $company, CarbonImmutable $from, bool $applying = false): void
    {
        $period = $from->format('Y-m');
        $later = $this->payrolls->query(Run::class, $company)->where('period', '>=', $period);
        if (! $this->enabled($company)) {
            if ((clone $later)->where('status', '!=', Run::DRAFT)->exists()) {
                throw PayrollException::incrementMonthLocked($period);
            }

            return;
        }
        $max = (int) $this->rules->get('payroll.increment_arrears_max_months', $this->contexts->forOrganization($company));
        if ($from->startOfMonth()->lessThan(CarbonImmutable::now('UTC')->startOfMonth()->subMonths($max))) {
            throw ValidationException::withMessages(['effective_from' => __('payroll::payroll.validation.increment_too_old', ['months' => $max])]);
        }
        if ($applying) {
            $waiting = (clone $later)->where('status', Run::PENDING)->orderBy('period')->value('period');
            if ($waiting !== null) {
                throw PayrollException::incrementMonthWaiting((string) $waiting);
            }
        }
    }

    /**
     * The months already paid a round reaches: their runs by id.
     *
     * @return Collection<string, Run>
     */
    public function paidRuns(Organization $company, IncrementRound $round): Collection
    {
        if (! $this->enabled($company)) {
            return collect();
        }

        return $this->payrolls->query(Run::class, $company)->where('period', '>=', $round->effective_from->format('Y-m'))
            ->whereIn('status', [Run::APPROVED, Run::PAID])->orderBy('period')->get()->keyBy('id');
    }

    /**
     * A line's arrears, month by month (nothing saved).
     *
     * @param  Collection<string, Run>  $runs  From paidRuns().
     * @param  Collection<string, Component>  $components
     * @return list<array{period: string, slip_id: string, previous_minor: int, new_minor: int, amount_minor: int, taxable_minor: int, pf_base_minor: int}>
     */
    public function forLine(Organization $company, IncrementRound $round, IncrementLine $line, Collection $runs, Collection $components): array
    {
        if ($runs->isEmpty() || $line->excluded || $line->new_minor <= $line->current_minor || $line->salary_id === null) {
            return [];
        }
        $employee = $this->directory->find($company, $line->employee_id);
        $salary = $this->payrolls->query(Salary::class, $company)->whereKey($line->salary_id)->first();
        if ($employee === null || $salary === null) {
            return [];
        }
        // The salary in force in those months: paid at the old basic, structure and all.
        $slips = $this->payrolls->query(Slip::class, $company)->whereIn('run_id', $runs->keys()->all())->where('employee_id', $line->employee_id)
            ->where('salary_id', $line->salary_id)->whereNull('problem')->get();
        $items = $this->runs->itemsFor($company, $salary, $components);
        $rules = [...$this->runs->ruleSet($employee), 'late_policy' => 'none', 'early_policy' => 'none', 'tax_slabs' => []];

        $months = [];
        foreach ($slips->sortBy(fn (Slip $slip) => $runs[$slip->run_id]->period) as $slip) {
            $run = $runs[$slip->run_id];
            $facts = [
                'items' => $items, 'period_days' => $slip->period_days, 'employed_days' => $slip->employed_days, 'absent_days' => $slip->absent_days,
                'half_days' => $slip->half_days, 'unpaid_leave_halves' => $slip->unpaid_leave_halves, 'overtime_minutes' => 0, 'late_minutes' => 0, 'adjustments' => [],
            ];
            $old = self::structureEarnings(PayCalculator::slip([...$facts, 'basic' => $line->current_minor], $rules));
            $new = self::structureEarnings(PayCalculator::slip([...$facts, 'basic' => $line->new_minor], $rules));
            // A raise from the middle of a month counts from that day.
            $days = (int) $run->period_from->diffInDays($run->period_to) + 1;
            $covered = (int) $run->period_from->max($round->effective_from)->diffInDays($run->period_to) + 1;
            $part = fn (int $amount) => $covered >= $days ? $amount : intdiv($amount * $covered * 2 + $days, $days * 2);
            $amount = $part($new['total'] - $old['total']);
            if ($amount <= 0) {
                continue;
            }
            $months[] = [
                'period' => $run->period, 'slip_id' => (string) $slip->getKey(), 'previous_minor' => $old['total'], 'new_minor' => $new['total'],
                'amount_minor' => $amount, 'taxable_minor' => $part($new['taxable'] - $old['taxable']), 'pf_base_minor' => $part($new['basic'] - $old['basic']),
            ];
        }

        return $months;
    }

    /** The structure's components, for itemsFor(). @return Collection<string, Component> */
    public function components(Organization $company): Collection
    {
        return $this->payrolls->query(Component::class, $company)->get()->keyBy('id');
    }

    /**
     * The earnings of a worked-out slip: in all, taxable, and the basic.
     *
     * @param  array{lines: list<array{kind: string, code: string, amount: int, taxable: bool}>}  $result
     * @return array{total: int, taxable: int, basic: int}
     */
    private static function structureEarnings(array $result): array
    {
        $earnings = array_filter($result['lines'], fn (array $line) => $line['kind'] === 'earning');

        return [
            'total' => array_sum(array_column($earnings, 'amount')),
            'taxable' => array_sum(array_map(fn (array $line) => $line['taxable'] ? $line['amount'] : 0, $earnings)),
            'basic' => array_sum(array_map(fn (array $line) => $line['code'] === 'BASIC' ? $line['amount'] : 0, $earnings)),
        ];
    }
}
