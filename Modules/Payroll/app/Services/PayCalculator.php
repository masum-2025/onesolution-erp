<?php

namespace Modules\Payroll\Services;

/**
 * One employee's pay for a month, from facts only (no database), in
 * integer minor units with half-up rounding:
 *
 * - Basic and the structure's items (fixed, or basis points of the basic).
 * - Prorated items are paid for the days employed, less absences and half
 *   days when the company deducts them (payroll.deduct_absence):
 *   amount x payable half-days / (2 x days in the month).
 * - Half days of leave without pay (Leave module) are always taken off.
 * - Days to pay from other modules (unused leave paid out): a day = the
 *   basic or gross over payroll.encashment_day_divisor.
 * - Over time: minutes x hourly rate x multiplier, the hourly rate being
 *   the basic (or the full gross) over payroll.monthly_hours.
 * - Coming late (payroll.late_policy): each minute at the basic's minute
 *   rate (per_minute), or every N late days cut a half or whole day's basic
 *   (count: payroll.late_count_per_cut / late_count_cut). Leaving early
 *   (payroll.early_leave_policy) is counted the same way. A day's basic is
 *   the basic over the days in the month.
 * - Tax at source: the month's taxable earnings x 12 through the slabs
 *   (payroll.tax_slabs: cumulative "up to" bands, null = the rest), a
 *   twelfth of it each month.
 * - A net below zero is a problem the run cannot be sent with.
 */
final class PayCalculator
{
    /**
     * @param  array{basic: int, items: list<array{code: string, name: array<string, string>, kind: string, taxable: bool, prorated: bool, calc: string, amount: int|null, rate_bp: int|null}>, period_days: int, employed_days: int, absent_days: int, half_days: int, overtime_minutes: int, late_minutes: int, late_days?: int, early_days?: int, unpaid_leave_halves?: int, paid_days?: list<array{code: string, name: array<string, string>, halves: int, taxable: bool}>, adjustments: list<array{kind: string, label: string, amount: int, taxable: bool}>, earnings?: list<array{code: string, name: array<string, string>, amount: int, taxable: bool}>, loans?: list<int>, pf?: array{0: int, 1: int}}  $facts
     * @param  array{deduct_absence: bool, overtime_multiplier_bp: int, overtime_base: string, monthly_hours: int, late_policy: string, late_per_cut?: int, late_cut_halves?: int, early_policy?: string, early_per_cut?: int, early_cut_halves?: int, encash_base?: string, encash_divisor?: int, tax_slabs: list<array{0: int|null, 1: int}>}  $rules
     * @return array{lines: list<array{kind: string, code: string, name: array<string, string>, amount: int, taxable: bool}>, earnings: int, deductions: int, tax: int, net: int, problem: string|null}
     */
    public static function slip(array $facts, array $rules): array
    {
        // Leave without pay is never paid, whatever payroll.deduct_absence says.
        $half = 2 * $facts['employed_days'] - ($rules['deduct_absence'] ? 2 * $facts['absent_days'] + $facts['half_days'] : 0) - ($facts['unpaid_leave_halves'] ?? 0);
        $payable = max(0, $half);
        $whole = 2 * max(1, $facts['period_days']);
        $prorate = fn (int $amount) => intdiv($amount * $payable + intdiv($whole, 2), $whole);

        $lines = [['kind' => 'earning', 'code' => 'BASIC', 'name' => ['en' => 'Basic', 'bn' => 'মূল বেতন'], 'amount' => $prorate($facts['basic']), 'taxable' => true]];
        $gross = $facts['basic'];
        foreach ($facts['items'] as $item) {
            $full = $item['calc'] === 'percent_of_basic' ? self::share($facts['basic'], (int) $item['rate_bp']) : (int) $item['amount'];
            if ($item['kind'] === 'earning') {
                $gross += $full;
            }
            $lines[] = [
                'kind' => $item['kind'], 'code' => $item['code'], 'name' => $item['name'],
                'amount' => $item['prorated'] ? $prorate($full) : $full, 'taxable' => $item['kind'] === 'earning' && $item['taxable'],
            ];
        }

        $minuteBase = max(1, $rules['monthly_hours']) * 60;
        if ($facts['overtime_minutes'] > 0 && $rules['overtime_multiplier_bp'] > 0) {
            $base = $rules['overtime_base'] === 'gross' ? $gross : $facts['basic'];
            $lines[] = [
                'kind' => 'earning', 'code' => 'OVERTIME', 'name' => ['en' => 'Over time', 'bn' => 'ওভারটাইম'],
                'amount' => self::divide($base * $facts['overtime_minutes'] * $rules['overtime_multiplier_bp'], $minuteBase * 10000), 'taxable' => true,
            ];
        }
        if ($rules['late_policy'] === 'per_minute' && $facts['late_minutes'] > 0) {
            $lines[] = [
                'kind' => 'deduction', 'code' => 'LATE', 'name' => ['en' => 'Late arrival', 'bn' => 'দেরিতে আসা'],
                'amount' => self::divide($facts['basic'] * $facts['late_minutes'], $minuteBase), 'taxable' => false,
            ];
        }
        if ($rules['late_policy'] === 'count') {
            $lines[] = [
                'kind' => 'deduction', 'code' => 'LATE', 'name' => ['en' => 'Late arrival', 'bn' => 'দেরিতে আসা'],
                'amount' => self::countCut($facts['basic'], $facts['period_days'], $facts['late_days'] ?? 0, $rules['late_per_cut'] ?? 3, $rules['late_cut_halves'] ?? 2), 'taxable' => false,
            ];
        }
        if (($rules['early_policy'] ?? 'none') === 'count') {
            $lines[] = [
                'kind' => 'deduction', 'code' => 'EARLY_LEAVE', 'name' => ['en' => 'Leaving early', 'bn' => 'আগে বের হওয়া'],
                'amount' => self::countCut($facts['basic'], $facts['period_days'], $facts['early_days'] ?? 0, $rules['early_per_cut'] ?? 3, $rules['early_cut_halves'] ?? 2), 'taxable' => false,
            ];
        }
        // Provident fund on the basic paid: the employee's share comes off pay; the
        // company's is shown (kind "employer") but changes neither earnings nor net.
        if (isset($facts['pf']) && $lines[0]['amount'] > 0) {
            [$employeeBp, $employerBp] = $facts['pf'];
            $lines[] = ['kind' => 'deduction', 'code' => 'PF_EMPLOYEE', 'name' => ['en' => 'Provident fund', 'bn' => 'ভবিষ্য তহবিল'], 'amount' => self::share($lines[0]['amount'], $employeeBp), 'taxable' => false];
            $lines[] = ['kind' => 'employer', 'code' => 'PF_EMPLOYER', 'name' => ['en' => 'Provident fund (company)', 'bn' => 'ভবিষ্য তহবিল (কোম্পানি)'], 'amount' => self::share($lines[0]['amount'], $employerBp), 'taxable' => false];
        }
        // Loan instalments of the month (worked out from each loan's balance by Runs).
        foreach ($facts['loans'] ?? [] as $amount) {
            $lines[] = ['kind' => 'deduction', 'code' => 'LOAN', 'name' => ['en' => 'Loan instalment', 'bn' => 'ঋণের কিস্তি'], 'amount' => (int) $amount, 'taxable' => false];
        }
        // Days other modules say are to be paid (DaysToPay: unused leave paid out), at a day of the
        // basic or the gross (payroll.encashment_base) over payroll.encashment_day_divisor days.
        foreach ($facts['paid_days'] ?? [] as $days) {
            $base = ($rules['encash_base'] ?? 'basic') === 'gross' ? $gross : $facts['basic'];
            $lines[] = ['kind' => 'earning', 'code' => $days['code'], 'name' => $days['name'], 'amount' => self::divide($base * (int) $days['halves'], 2 * max(1, (int) ($rules['encash_divisor'] ?? 30))), 'taxable' => (bool) $days['taxable']];
        }
        // Earnings other modules work out (EarningSource: piece rates, commission).
        foreach ($facts['earnings'] ?? [] as $earning) {
            $lines[] = ['kind' => 'earning', 'code' => $earning['code'], 'name' => $earning['name'], 'amount' => (int) $earning['amount'], 'taxable' => (bool) $earning['taxable'], 'posting_key' => $earning['posting_key'] ?? null];
        }
        foreach ($facts['adjustments'] as $adjustment) {
            $lines[] = [
                'kind' => $adjustment['kind'], 'code' => 'ADJUSTMENT', 'name' => ['en' => $adjustment['label']],
                'amount' => $adjustment['amount'], 'taxable' => $adjustment['kind'] === 'earning' && $adjustment['taxable'],
            ];
        }

        $lines = array_values(array_filter($lines, fn (array $line) => $line['amount'] > 0));
        $earnings = array_sum(array_map(fn (array $line) => $line['kind'] === 'earning' ? $line['amount'] : 0, $lines));
        $deductions = array_sum(array_map(fn (array $line) => $line['kind'] === 'deduction' ? $line['amount'] : 0, $lines));
        $taxable = array_sum(array_map(fn (array $line) => $line['taxable'] ? $line['amount'] : 0, $lines));

        $tax = self::monthlyTax($taxable, $rules['tax_slabs']);
        if ($tax > 0) {
            $lines[] = ['kind' => 'tax', 'code' => 'TAX', 'name' => ['en' => 'Income tax at source', 'bn' => 'উৎসে আয়কর'], 'amount' => $tax, 'taxable' => false];
        }
        $net = $earnings - $deductions - $tax;

        return ['lines' => $lines, 'earnings' => $earnings, 'deductions' => $deductions, 'tax' => $tax, 'net' => $net, 'problem' => $net < 0 ? 'negative_net' : null];
    }

    /**
     * A month's tax: the yearly tax on twelve times the month's taxable
     * earnings, through cumulative bands, divided by twelve.
     *
     * @param  list<array{0: int|null, 1: int}>  $slabs  [up to (minor units, null = the rest), rate in basis points]
     */
    public static function monthlyTax(int $monthlyTaxable, array $slabs): int
    {
        return self::divide(self::yearlyTax($monthlyTaxable * 12, $slabs), 12);
    }

    /**
     * Tax at source on a festival bonus: the extra yearly tax the bonus
     * brings on top of twelve months of the regular taxable pay.
     *
     * @param  list<array{0: int|null, 1: int}>  $slabs
     */
    public static function bonusTax(int $monthlyTaxable, int $bonus, array $slabs): int
    {
        $base = $monthlyTaxable * 12;

        return self::yearlyTax($base + $bonus, $slabs) - self::yearlyTax($base, $slabs);
    }

    /** Each instalment of a loan: the principal over the instalments, rounded up (the last one takes what is left). */
    public static function installment(int $principal, int $installments): int
    {
        $count = max(1, $installments);

        return intdiv($principal + $count - 1, $count);
    }

    /**
     * Gratuity on leaving: days of the basic for each whole year of service
     * (a month's basic stands for 30 days), from a minimum of years.
     */
    public static function gratuity(int $basic, int $years, int $minYears, int $daysPerYear): int
    {
        if ($daysPerYear <= 0 || $years < max(1, $minYears)) {
            return 0;
        }

        return self::divide($basic * $daysPerYear * $years, 30);
    }

    /**
     * The share (basis points) of the company's provident fund contributions
     * someone takes with them after these years of service: the last step
     * reached; no steps means all of it.
     *
     * @param  list<array{0: int, 1: int}>  $steps  [after years, basis points], ascending
     */
    public static function vestedShare(int $years, array $steps): int
    {
        if ($steps === []) {
            return 10000;
        }
        $share = 0;
        foreach ($steps as [$after, $basisPoints]) {
            if ($years >= $after) {
                $share = $basisPoints;
            }
        }

        return min(10000, $share);
    }

    /**
     * A cut by counting days (late or left early): every $perCut days make
     * one cut of $halves half days of the basic (a day = the basic over the
     * days in the month). 7 late days at 3 per cut = 2 cuts.
     */
    public static function countCut(int $basic, int $periodDays, int $days, int $perCut, int $halves): int
    {
        $cuts = intdiv(max(0, $days), max(1, $perCut));

        return self::divide($basic * $cuts * max(0, $halves), 2 * max(1, $periodDays));
    }

    /** Whole months of service from the day someone joined up to a day. */
    public static function serviceMonths(string $joined, string $on): int
    {
        [$y1, $m1, $d1] = array_map('intval', explode('-', $joined));
        [$y2, $m2, $d2] = array_map('intval', explode('-', $on));
        $months = ($y2 - $y1) * 12 + ($m2 - $m1) - ($d2 < $d1 ? 1 : 0);

        return max(0, $months);
    }

    /**
     * Yearly tax on a yearly taxable amount through cumulative bands.
     *
     * @param  list<array{0: int|null, 1: int}>  $slabs
     */
    private static function yearlyTax(int $yearly, array $slabs): int
    {
        $done = 0;
        $basisPoints = 0;
        foreach ($slabs as [$upto, $rate]) {
            if ($yearly <= $done) {
                break;
            }
            $top = $upto === null ? $yearly : min($yearly, $upto);
            if ($top > $done) {
                $basisPoints += ($top - $done) * $rate;
                $done = $top;
            }
        }

        return self::divide($basisPoints, 10000);
    }

    /** "1.5" -> 15000, "2" -> 20000, "7.25" -> 72500 (a decimal string as basis points; null when not one). */
    public static function toBasisPoints(string $text, int $scale = 10000): ?int
    {
        if (! preg_match('/^(\d{1,4})(?:\.(\d{1,4}))?$/', trim($text), $match)) {
            return null;
        }
        $digits = strlen((string) $scale) - 1;

        return (int) $match[1] * $scale + (int) str_pad(substr($match[2] ?? '', 0, $digits), $digits, '0');
    }

    /** Basis points of an amount, half up. */
    public static function share(int $amount, int $basisPoints): int
    {
        return self::divide($amount * $basisPoints, 10000);
    }

    private static function divide(int $value, int $by): int
    {
        return intdiv($value + intdiv($by, 2), $by);
    }
}
