<?php

namespace Modules\Pos\Services;

use Modules\Accounting\Services\TaxCodes;

/**
 * The arithmetic of a sale, pure and in integers: each line's amount
 * (quantity in thousandths times the unit price, half up), its discount,
 * its tax (inside or on top of the price), and the sale's totals; what was
 * paid against it and the change, which only cash can give.
 */
final class Pricing
{
    /**
     * @param  list<array{quantity_milli: int, unit_price_minor: int, discount_minor: int, tax_rate_bp: int}>  $lines
     * @return array{lines: list<array{gross: int, discount: int, net: int, tax: int, total: int}>, subtotal: int, discount: int, tax: int, total: int}
     */
    public static function sale(array $lines, bool $pricesIncludeTax): array
    {
        $out = [];
        foreach ($lines as $line) {
            $gross = self::divide($line['quantity_milli'] * $line['unit_price_minor'], 1000);
            $discount = min($gross, max(0, $line['discount_minor']));
            $split = TaxCodes::split($gross - $discount, $line['tax_rate_bp'], $pricesIncludeTax);
            $out[] = ['gross' => $gross, 'discount' => $discount, 'net' => $split['net'], 'tax' => $split['tax'], 'total' => $split['net'] + $split['tax']];
        }

        return [
            'lines' => $out,
            'subtotal' => array_sum(array_column($out, 'gross')),
            'discount' => array_sum(array_column($out, 'discount')),
            'tax' => array_sum(array_column($out, 'tax')),
            'total' => array_sum(array_column($out, 'total')),
        ];
    }

    /**
     * Paid against owed: the change (from cash only), or null when short.
     *
     * @param  list<array{method: string, amount_minor: int}>  $payments
     * @return array{paid: int, change: int, cash: int}|null
     */
    public static function settle(int $owed, array $payments): ?array
    {
        $paid = array_sum(array_column($payments, 'amount_minor'));
        if ($paid < $owed) {
            return null;
        }
        $cash = array_sum(array_map(fn (array $payment) => $payment['method'] === 'cash' ? $payment['amount_minor'] : 0, $payments));

        return ['paid' => $paid, 'change' => $paid - $owed, 'cash' => $cash];
    }

    /** The discount as basis points of the amount before it (0 when nothing). */
    public static function discountShare(int $discount, int $subtotal): int
    {
        return $subtotal <= 0 ? 0 : self::divide($discount * 10000, $subtotal);
    }

    /** Part of an amount for part of a quantity, half up (what a returned quantity is worth). */
    public static function part(int $amount, int $partMilli, int $wholeMilli): int
    {
        return $partMilli >= $wholeMilli ? $amount : self::divide($amount * $partMilli, max(1, $wholeMilli));
    }

    private static function divide(int $value, int $by): int
    {
        return intdiv($value + intdiv($by, 2), $by);
    }
}
