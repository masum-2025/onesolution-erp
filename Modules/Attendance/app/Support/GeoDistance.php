<?php

namespace Modules\Attendance\Support;

/**
 * Metres between two points given in millionths of a degree (the great-
 * circle distance on a sphere of the Earth's mean radius). Points are
 * stored as integers; the trigonometry in between is not money and ends in
 * whole metres.
 */
final class GeoDistance
{
    /** The Earth's mean radius in metres. */
    private const EARTH_RADIUS_M = 6371008;

    public static function metres(int $latitudeA, int $longitudeA, int $latitudeB, int $longitudeB): int
    {
        $lat1 = deg2rad($latitudeA / 1000000);
        $lat2 = deg2rad($latitudeB / 1000000);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad(($longitudeB - $longitudeA) / 1000000);

        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return (int) round(2 * self::EARTH_RADIUS_M * asin(min(1, sqrt($h))));
    }

    /** Whether millionths of a degree are a real latitude and longitude. */
    public static function valid(int $latitude, int $longitude): bool
    {
        return abs($latitude) <= 90000000 && abs($longitude) <= 180000000;
    }
}
