<?php

namespace App\Platform\Identity\Support;

/**
 * A short browser and platform name from a User-Agent, for the person's own
 * list of signed-in devices ("Edge on Windows"). Rough on purpose: it only
 * helps people recognise their own devices.
 */
final class DeviceLabel
{
    /**
     * @return array{browser: string|null, platform: string|null}
     */
    public static function from(?string $userAgent): array
    {
        $agent = (string) $userAgent;

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        $platform = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return ['browser' => $browser, 'platform' => $platform];
    }
}
