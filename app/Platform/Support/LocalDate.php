<?php

namespace App\Platform\Support;

use Carbon\CarbonInterface;

/**
 * A date as people read it in messages, in their language and digits:
 * "4 November 2026", "৪ নভেম্বর ২০২৬".
 */
final class LocalDate
{
    public static function format(CarbonInterface $date, ?string $locale = null): string
    {
        return $date->copy()->locale($locale ?? app()->getLocale())->isoFormat('D MMMM YYYY');
    }
}
