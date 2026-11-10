<?php

namespace Modules\Diagnostics\Services;

/**
 * Which normal range fits a patient (sex and age in days; a sex-specific
 * range before "any", the narrowest age band first) and how a value compares
 * with it: critical_low / low / high / critical_high for numbers, abnormal
 * for text that differs from the normal text. Pure; decimals are compared as
 * whole numbers of ten-thousandths, never as floats.
 */
final class ResultFlags
{
    private const SCALE = 4;

    /**
     * @param  list<array{sex: string, age_from_days: int, age_to_days: int|null, low: string|null, high: string|null, critical_low: string|null, critical_high: string|null, normal_text: string|null}>  $ranges
     * @return array{sex: string, age_from_days: int, age_to_days: int|null, low: string|null, high: string|null, critical_low: string|null, critical_high: string|null, normal_text: string|null}|null
     */
    public static function rangeFor(array $ranges, ?string $sex, ?int $ageDays): ?array
    {
        $fits = array_filter($ranges, fn (array $range) => ($range['sex'] === 'any' || $range['sex'] === $sex)
            && ($ageDays === null ? $range['age_from_days'] === 0 && $range['age_to_days'] === null
                : $ageDays >= $range['age_from_days'] && ($range['age_to_days'] === null || $ageDays <= $range['age_to_days'])));
        usort($fits, fn (array $a, array $b) => [($a['sex'] === 'any' ? 1 : 0), ($a['age_to_days'] ?? PHP_INT_MAX) - $a['age_from_days']]
            <=> [($b['sex'] === 'any' ? 1 : 0), ($b['age_to_days'] ?? PHP_INT_MAX) - $b['age_from_days']]);

        return $fits[0] ?? null;
    }

    /**
     * @param  array{low: string|null, high: string|null, critical_low: string|null, critical_high: string|null, normal_text: string|null}|null  $range
     */
    public static function flag(string $resultType, ?string $value, ?array $range): ?string
    {
        if ($value === null || $value === '' || $range === null) {
            return null;
        }
        if ($resultType !== 'number') {
            $normal = trim((string) ($range['normal_text'] ?? ''));

            return $normal !== '' && mb_strtolower(trim($value)) !== mb_strtolower($normal) ? 'abnormal' : null;
        }
        $number = self::scaled($value);
        if ($number === null) {
            return null;
        }
        $limit = fn (?string $bound) => $bound === null ? null : self::scaled($bound);

        return match (true) {
            ($l = $limit($range['critical_low'])) !== null && $number <= $l => 'critical_low',
            ($h = $limit($range['critical_high'])) !== null && $number >= $h => 'critical_high',
            ($l = $limit($range['low'])) !== null && $number < $l => 'low',
            ($h = $limit($range['high'])) !== null && $number > $h => 'high',
            default => null,
        };
    }

    /** A decimal text ("12.5", "-0.03", Bangla digits too) as ten-thousandths, or null when it is not a number. */
    public static function scaled(string $value): ?int
    {
        $value = strtr(trim($value), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        if (! preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,4}))?$/', $value, $match)) {
            return null;
        }
        $whole = (int) $match[2] * 10 ** self::SCALE + (int) str_pad($match[3] ?? '', self::SCALE, '0');

        return $match[1] === '-' ? -$whole : $whole;
    }

    /** A number typed in any usual way, written back plainly with the parameter's decimal places ("12.50"). */
    public static function normalised(string $value, int $decimals): ?string
    {
        $scaled = self::scaled($value);
        if ($scaled === null) {
            return null;
        }
        $places = max(0, min(self::SCALE, $decimals));
        $step = 10 ** (self::SCALE - $places);
        $rounded = intdiv(abs($scaled) + intdiv($step, 2), $step);
        $text = $places === 0 ? (string) $rounded : intdiv($rounded, 10 ** $places).'.'.str_pad((string) ($rounded % 10 ** $places), $places, '0', STR_PAD_LEFT);

        return ($scaled < 0 && $rounded > 0 ? '-' : '').$text;
    }
}
