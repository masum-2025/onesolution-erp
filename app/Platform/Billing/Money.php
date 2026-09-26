<?php

namespace App\Platform\Billing;

/**
 * Integer money arithmetic. Amounts are minor units; rates are basis points
 * (1/100 of a percent). Rounding is half up, the same on every database and
 * every machine, because no float is ever involved.
 */
final class Money
{
    public const BASIS = 10000;

    /**
     * The given share of an amount: share(1999, 1500) = 300 (15% of 19.99 is 3.00).
     */
    public static function share(int $amountMinor, int $basisPoints): int
    {
        $sign = $amountMinor < 0 ? -1 : 1;

        return $sign * intdiv(abs($amountMinor) * $basisPoints + intdiv(self::BASIS, 2), self::BASIS);
    }
}
