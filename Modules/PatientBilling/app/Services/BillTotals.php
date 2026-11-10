<?php

namespace Modules\PatientBilling\Services;

use Modules\Accounting\Services\TaxCodes;

/**
 * A bill's figures, in whole minor units, from its lines: each line's gross
 * (price × quantity), the panel's discount (a percent of the subtotal) and
 * any other discount (an amount), shared over the lines by their gross (the
 * remainder to the biggest lines), and each line's tax from its rate, inside
 * or on top of the price (rule patient_billing.prices_include_tax). Pure: no
 * database, no rounding drift (the lines always add up to the bill).
 */
final class BillTotals
{
    /**
     * @param  list<array{quantity_milli: int, unit_price_minor: int, tax_rate_bp: int}>  $lines
     * @return array{lines: list<array{gross: int, discount: int, tax: int, total: int}>, subtotal: int, panel_discount: int, discount: int, tax: int, total: int}
     */
    public static function of(array $lines, int $panelPercent, int $discountMinor, bool $pricesIncludeTax): array
    {
        $gross = array_map(fn (array $line) => self::gross($line['quantity_milli'], $line['unit_price_minor']), $lines);
        $subtotal = array_sum($gross);
        $panel = intdiv($subtotal * max(0, min(100, $panelPercent)) + 50, 100);
        $other = max(0, min($discountMinor, $subtotal - $panel));
        $shares = self::share($panel + $other, $gross);

        $out = [];
        foreach ($lines as $index => $line) {
            $split = TaxCodes::split($gross[$index] - $shares[$index], (int) $line['tax_rate_bp'], $pricesIncludeTax);
            $out[] = ['gross' => $gross[$index], 'discount' => $shares[$index], 'tax' => $split['tax'], 'total' => $split['net'] + $split['tax']];
        }

        return [
            'lines' => $out,
            'subtotal' => $subtotal,
            'panel_discount' => $panel,
            'discount' => $other,
            'tax' => array_sum(array_column($out, 'tax')),
            'total' => array_sum(array_column($out, 'total')),
        ];
    }

    /** Price × quantity (thousandths), half up. */
    public static function gross(int $quantityMilli, int $unitPriceMinor): int
    {
        return intdiv($quantityMilli * $unitPriceMinor + 500, 1000);
    }

    /**
     * An amount shared by weights: floors first, then one unit each to the biggest weights until it adds up.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    public static function share(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($amount <= 0 || $total <= 0) {
            return array_fill(0, count($weights), 0);
        }
        $parts = array_map(fn (int $weight) => intdiv($amount * $weight, $total), $weights);
        $left = $amount - array_sum($parts);
        $order = array_keys($weights);
        usort($order, fn ($a, $b) => $weights[$b] <=> $weights[$a] ?: $a <=> $b);
        for ($i = 0; $left > 0; $i = ($i + 1) % count($order)) {
            $parts[$order[$i]]++;
            $left--;
        }

        return $parts;
    }
}
