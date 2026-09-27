<?php

namespace App\Platform\Support;

use NumberFormatter;

/**
 * Money as people read it in messages and documents, from integer minor
 * units without floats: "BDT 7,000.00", "BDT ৭,০০০.০০".
 */
final class MoneyText
{
    public static function format(int $minor, string $currency, string $locale): string
    {
        $digits = self::fractionDigits($currency);

        $scale = 10 ** $digits;
        $whole = intdiv(abs($minor), $scale);
        $fraction = str_pad((string) (abs($minor) % $scale), $digits, '0', STR_PAD_LEFT);

        $numbers = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $text = $numbers->format($whole);

        if ($digits > 0) {
            $localDigits = implode('', array_map(fn (string $digit) => $numbers->format((int) $digit), str_split($fraction)));
            $text .= $numbers->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL).$localDigits;
        }

        return ($minor < 0 ? '-' : '')."{$currency} {$text}";
    }

    public static function fractionDigits(string $currency): int
    {
        $formatter = new NumberFormatter("en@currency={$currency}", NumberFormatter::CURRENCY);

        return (int) $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }
}
