<?php

namespace App\Platform\Payments;

use App\Platform\Support\MoneyText;

/**
 * Gateways speak decimal text ("299.00"); we keep integer minor units.
 * Converted with string arithmetic only, never floats.
 */
final class DecimalAmount
{
    /** 29900 BDT -> "299.00" */
    public static function fromMinor(int $minor, string $currency): string
    {
        $digits = MoneyText::fractionDigits($currency);
        $scale = 10 ** $digits;
        $text = (string) intdiv(abs($minor), $scale);

        if ($digits > 0) {
            $text .= '.'.str_pad((string) (abs($minor) % $scale), $digits, '0', STR_PAD_LEFT);
        }

        return ($minor < 0 ? '-' : '').$text;
    }

    /**
     * "299.00" or "299" BDT -> 29900. null when it is not a plain, exact amount
     * (more decimals than the currency has are only accepted when they are zeros).
     */
    public static function toMinor(?string $text, string $currency): ?int
    {
        $text = trim((string) $text);
        if (! preg_match('/^(\d{1,15})(?:\.(\d{1,8}))?$/', $text, $match)) {
            return null;
        }

        $digits = MoneyText::fractionDigits($currency);
        $fraction = $match[2] ?? '';

        if (strlen($fraction) > $digits) {
            if (trim(substr($fraction, $digits), '0') !== '') {
                return null;
            }
            $fraction = substr($fraction, 0, $digits);
        }

        return (int) $match[1] * (10 ** $digits) + (int) str_pad($fraction, $digits, '0');
    }
}
