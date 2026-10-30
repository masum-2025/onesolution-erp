<?php

namespace Modules\Compliance\Support;

use Carbon\CarbonImmutable;

/**
 * Where an audit stands, always worked out from its official expiry day and
 * today (the company's), never from a stored counter. A renewal under way
 * changes nothing here: an expired audit stays expired until a new expiry
 * day is set.
 */
final class Validity
{
    public const VALID = 'valid';

    public const EXPIRING = 'expiring';

    public const EXPIRED = 'expired';

    /** No expiry day known. */
    public const UNKNOWN = 'unknown';

    public const STATES = [self::VALID, self::EXPIRING, self::EXPIRED, self::UNKNOWN];

    /** A plain day (Y-m-d) from another side's message, or null when it is not a real day. */
    public static function day(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        // 2026-02-31 rolls over to March: not the day that was written.
        return $day->toDateString() === $value ? $value : null;
    }

    /** Whole days from today to the expiry day: 0 on the day itself, negative once past. */
    public static function daysLeft(?CarbonImmutable $expiresOn, CarbonImmutable $today): ?int
    {
        if ($expiresOn === null) {
            return null;
        }

        return (int) CarbonImmutable::parse($today->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($expiresOn->toDateString(), 'UTC'), false);
    }

    /** Valid until its last day (inclusive); "expiring" within the rule's days of it. */
    public static function state(?CarbonImmutable $expiresOn, CarbonImmutable $today, int $expiringDays): string
    {
        $left = self::daysLeft($expiresOn, $today);

        return match (true) {
            $left === null => self::UNKNOWN,
            $left < 0 => self::EXPIRED,
            $left <= $expiringDays => self::EXPIRING,
            default => self::VALID,
        };
    }

    /**
     * The reminder stage due today, or null: -1 once expired, else the
     * smallest stage of the rule (days before) that the days left reach.
     *
     * @param  list<int>  $days  e.g. [60, 30, 15, 7]
     */
    public static function stage(?CarbonImmutable $expiresOn, CarbonImmutable $today, array $days): ?int
    {
        $left = self::daysLeft($expiresOn, $today);
        if ($left === null || $days === []) {
            return null;
        }
        if ($left < 0) {
            return -1;
        }
        sort($days);
        foreach ($days as $stage) {
            if ($left <= $stage) {
                return $stage;
            }
        }

        return null;
    }
}
