<?php

namespace App\Platform\Branding;

/**
 * WCAG contrast checks for brand colors. The app puts white or near-black
 * text on the brand color (whichever reads better) and uses the brand color
 * for links and borders on light surfaces, so a brand color must work both
 * ways. Same formula as resources/js/lib/brand.js.
 */
final class Contrast
{
    public const DARK_TEXT = '#111114';

    /** WCAG AA for normal text. */
    public const TEXT_MIN = 4.5;

    /** WCAG 1.4.11 for UI components on a light surface. */
    public const UI_MIN = 3.0;

    public static function ratio(string $a, string $b): float
    {
        [$hi, $lo] = [self::luminance($a), self::luminance($b)];
        if ($lo > $hi) {
            [$hi, $lo] = [$lo, $hi];
        }

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** Best contrast of white or near-black text on the color. */
    public static function textOn(string $hex): float
    {
        return max(self::ratio($hex, '#FFFFFF'), self::ratio($hex, self::DARK_TEXT));
    }

    /**
     * @return 'text'|'surface'|null Which check the color fails, or null when it passes.
     */
    public static function problem(string $hex, bool $usedOnSurface = true): ?string
    {
        return match (true) {
            self::textOn($hex) < self::TEXT_MIN => 'text',
            $usedOnSurface && self::ratio($hex, '#FFFFFF') < self::UI_MIN => 'surface',
            default => null,
        };
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(fn (string $part) => hexdec($part) / 255, str_split(ltrim($hex, '#'), 2));
        [$r, $g, $b] = array_map(fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
