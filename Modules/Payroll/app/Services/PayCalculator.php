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
 * - Over time: minutes x hourly rate x multiplier, the hourly rate being
 *   the basic (or the full gross) over payroll.monthly_hours.
 * - Late minutes are deducted at the basic's minute rate when the company
 *   says so (payroll.late_deduction).
 * - Tax at source: the month's taxable earnings x 12 through the slabs
 *   (payroll.tax_slabs: cumulative "up to" bands, null = the rest), a
 *   twelfth of it each month.
 * - A net below zero is a problem the run cannot be sent with.
 */
final class PayCalculator
{
    /**
     * @param  array{basic: int, items: list<array{code: string, name: array<string, string>, kind: string, taxable: bool, prorated: bool, calc: string, amount: int|null, rate_bp: int|null}>, period_days: int, employed_days: int, absent_days: int, half_days: int, overtime_minutes: int, late_minutes: int, adjustments: list<array{kind: string, label: string, amount: int, taxable: bool}>}  $facts
     * @param  array{deduct_absence: bool, overtime_multiplier_bp: int, overtime_base: string, monthly_hours: int, late_deduction: bool, tax_slabs: list<array{0: int|null, 1: int}>}  $rules
     * @return array{lines: list<array{kind: string, code: string, name: array<string, string>, amount: int, taxable: bool}>, earnings: int, deductions: int, tax: int, net: int, problem: string|null}
     */
    public static function slip(array $facts, array $rules): array
    {
        $half = 2 * $facts['employed_days'] - ($rules['deduct_absence'] ? 2 * $facts['absent_days'] + $facts['half_days'] : 0);
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
        if ($rules['late_deduction'] && $facts['late_minutes'] > 0) {
            $lines[] = [
                'kind' => 'deduction', 'code' => 'LATE', 'name' => ['en' => 'Late arrival', 'bn' => 'দেরিতে আসা'],
                'amount' => self::divide($facts['basic'] * $facts['late_minutes'], $minuteBase), 'taxable' => false,
            ];
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
        $yearly = $monthlyTaxable * 12;
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

        return self::divide(self::divide($basisPoints, 10000), 12);
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
