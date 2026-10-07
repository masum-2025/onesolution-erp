<?php

namespace App\Platform\Identity\Support;

use App\Platform\Countries\CountryCatalog;
use App\Platform\Countries\CountryDefinition;

/**
 * Phone numbers in one form (E.164, e.g. +8801712345678), from what people
 * type: with or without the country code, the trunk 0, spaces or dashes.
 * Country formats are data (database/data/countries, "phone").
 */
final class PhoneNumber
{
    /**
     * @return string|null E.164, or null when it is no valid mobile number of that country.
     */
    public static function normalize(string $input, ?string $country): ?string
    {
        // Bangla digits (typed on a Bangla keyboard) read as 0-9.
        $input = strtr($input, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $digits = preg_replace('/[^\d+]/', '', $input) ?? '';
        $countries = self::formats();

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
        foreach (self::formats() as $code => $format) {
            $prefix = '+'.$format['dial'];
            if (str_starts_with($e164, $prefix) && self::matches(substr($e164, strlen($prefix)), $format)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{dial: string, trunk: string, national: string}>
     */
    private static function formats(): array
    {
        return array_map(fn (CountryDefinition $country) => $country->phone, app(CountryCatalog::class)->all());
    }

    /**
     * @param  array{dial: string, trunk: string, national: string}  $format
     */
    private static function matches(string $national, array $format): bool
    {
        return preg_match('/^(?:'.$format['national'].')$/', $national) === 1;
    }
}
