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
        return ($minor < 0 ? '-' : '')."{$currency} ".self::number(abs($minor), $currency, $locale);
    }

    /**
     * Money as the app shows it on screen, in the reader's language's own
     * way ("৭,০০০.০০৳" in Bangla, "BDT 7,000.00" in English): the language's
     * currency pattern, taken from zero, around the exact number.
     */
    public static function shown(int $minor, string $currency, string $locale): string
    {
        $pattern = (new NumberFormatter($locale, NumberFormatter::CURRENCY))->formatCurrency(0, $currency);
        $zero = self::number(0, $currency, $locale);
        if ($pattern === false || ! str_contains($pattern, $zero)) {
            return self::format($minor, $currency, $locale);
        }

        return ($minor < 0 ? '-' : '').str_replace($zero, self::number(abs($minor), $currency, $locale), $pattern);
    }

    /** The amount's digits in the language, from minor units without floats. */
    private static function number(int $minor, string $currency, string $locale): string
    {
        $digits = self::fractionDigits($currency);

        $scale = 10 ** $digits;
        $whole = intdiv($minor, $scale);
        $fraction = str_pad((string) ($minor % $scale), $digits, '0', STR_PAD_LEFT);

        $numbers = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $text = $numbers->format($whole);

        if ($digits > 0) {
            $localDigits = implode('', array_map(fn (string $digit) => $numbers->format((int) $digit), str_split($fraction)));
            $text .= $numbers->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL).$localDigits;
        }

        return $text;
    }

    public static function fractionDigits(string $currency): int
    {
        $formatter = new NumberFormatter("en@currency={$currency}", NumberFormatter::CURRENCY);

        return (int) $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }
}
