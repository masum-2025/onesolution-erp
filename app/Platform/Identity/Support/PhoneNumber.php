<?php

namespace App\Platform\Identity\Support;

/**
 * Phone numbers in one form (E.164, e.g. +8801712345678), from what people
 * type: with or without the country code, the trunk 0, spaces or dashes.
 * Country formats are data (config/identity.php phone_countries).
 */
final class PhoneNumber
{
    /**
     * @return string|null E.164, or null when it is no valid mobile number of that country.
     */
    public static function normalize(string $input, ?string $country): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $input) ?? '';
        $countries = (array) config('identity.phone_countries');

        if (str_starts_with($digits, '+') || str_starts_with($digits, '00')) {
            $international = ltrim(preg_replace('/^00/', '', $digits) ?? '', '+');
            foreach ($countries as $code => $format) {
                if (str_starts_with($international, $format['dial'])) {
                    $national = substr($international, strlen($format['dial']));

                    return self::matches($national, $format) ? '+'.$format['dial'].$national : null;
                }
            }

            return null;
        }

        $format = $country === null ? null : ($countries[strtoupper($country)] ?? null);
        if ($format === null) {
            return null;
        }

        $national = $format['trunk'] !== '' && str_starts_with($digits, $format['trunk']) ? substr($digits, strlen($format['trunk'])) : $digits;
        // Typed with the country code but without the plus (e.g. 88017...).
        if (! self::matches($national, $format) && str_starts_with($digits, $format['dial'])) {
            $national = substr($digits, strlen($format['dial']));
        }

        return self::matches($national, $format) ? '+'.$format['dial'].$national : null;
    }

    /**
     * The country an E.164 number belongs to, among the known formats.
     */
    public static function country(string $e164): ?string
    {
        foreach ((array) config('identity.phone_countries') as $code => $format) {
            $prefix = '+'.$format['dial'];
            if (str_starts_with($e164, $prefix) && self::matches(substr($e164, strlen($prefix)), $format)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param  array{dial: string, trunk: string, national: string}  $format
     */
    private static function matches(string $national, array $format): bool
    {
        return preg_match('/^(?:'.$format['national'].')$/', $national) === 1;
    }
}
