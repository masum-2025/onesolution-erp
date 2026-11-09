<?php

namespace Modules\OnlineOrders\Services;

use Modules\Accounting\Services\TaxCodes;

/**
 * The arithmetic of an order, pure and in integers: each line's amount
 * (quantity in thousandths times the unit price, half up), its discount and
 * tax (inside or on top of the price), the delivery charge (no tax on it),
 * and the order's totals.
 */
final class OrderMath
{
    /**
     * @param  list<array{quantity_milli: int, unit_price_minor: int, discount_minor: int, tax_rate_bp: int}>  $lines
     * @return array{lines: list<array{gross: int, discount: int, net: int, tax: int, total: int}>, subtotal: int, discount: int, tax: int, goods: int, delivery: int, total: int}
     */
    public static function order(array $lines, bool $pricesIncludeTax, int $deliveryMinor): array
    {
        $out = [];
        foreach ($lines as $line) {
            $gross = self::divide($line['quantity_milli'] * $line['unit_price_minor'], 1000);
            $discount = min($gross, max(0, $line['discount_minor']));
            $split = TaxCodes::split($gross - $discount, $line['tax_rate_bp'], $pricesIncludeTax);
            $out[] = ['gross' => $gross, 'discount' => $discount, 'net' => $split['net'], 'tax' => $split['tax'], 'total' => $split['net'] + $split['tax']];
        }
        $goods = array_sum(array_column($out, 'total'));

        return [
            'lines' => $out,
            'subtotal' => array_sum(array_column($out, 'gross')),
            'discount' => array_sum(array_column($out, 'discount')),
            'tax' => array_sum(array_column($out, 'tax')),
            'goods' => $goods,
            'delivery' => max(0, $deliveryMinor),
            'total' => $goods + max(0, $deliveryMinor),
        ];
    }

    /** The discount as basis points of the amount before it (0 when nothing). */
    public static function discountShare(int $discount, int $subtotal): int
    {
        return $subtotal <= 0 ? 0 : self::divide($discount * 10000, $subtotal);
    }

    /** "12.5" (%) -> 1250 basis points (string maths); 0 when unreadable. */
    public static function percentToBp(string $text): int
    {
        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', trim($text), $match)) {
            return 0;
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    private static function divide(int $value, int $by): int
    {
        return intdiv($value + intdiv($by, 2), $by);
    }
}
