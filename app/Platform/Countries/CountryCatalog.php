<?php

namespace App\Platform\Countries;

use DateTimeZone;
use LogicException;

/**
 * Every country the platform knows, read from the data files
 * (database/data/countries/XX.php), not the database, so phone numbers and
 * organization defaults work before anything is synced. Adding a country is
 * adding a file (then `php artisan countries:sync`): no code change.
 */
final class CountryCatalog
{
    public const DAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];

    public const DATE_FORMATS = ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD', 'DD.MM.YYYY', 'DD-MM-YYYY'];

    /**
     * Country facts that are also rule defaults (field => rule key). A client
     * may override them within the rule's hierarchy; the country value is the
     * starting point.
     */
    public const RULES = [
        'weekendDays' => 'attendance.weekend_days',
        'fiscalYearStart' => 'accounting.fiscal_year_start',
        'weekStart' => 'regional.week_start',
        'dateFormat' => 'regional.date_format',
        'paymentGateways' => 'billing.payment_gateways',
        'merchantGateways' => 'online_payments.gateways',
    ];

    /** @var array<string, CountryDefinition> */
    private array $countries = [];

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            $country = self::definition($entry);
            if (isset($this->countries[$country->code])) {
                throw new LogicException("Country [{$country->code}] is defined twice.");
            }
            $this->countries[$country->code] = $country;
        }

        ksort($this->countries);
    }

    public static function fromDataFiles(?string $directory = null): self
    {
        $directory ??= (string) config('countries.path');

        $entries = [];
        foreach (glob(rtrim($directory, '/\\').'/*.php') ?: [] as $file) {
            $entries[] = require $file;
        }

        return new self($entries);
    }

    public function has(?string $code): bool
    {
        return $code !== null && isset($this->countries[strtoupper($code)]);
    }

    public function get(string $code): CountryDefinition
    {
        return $this->countries[strtoupper($code)] ?? throw new LogicException("Unknown country [{$code}].");
    }

    public function find(?string $code): ?CountryDefinition
    {
        return $code === null ? null : ($this->countries[strtoupper($code)] ?? null);
    }

    /**
     * @return array<string, CountryDefinition>
     */
    public function all(): array
    {
        return $this->countries;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->countries);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function definition(array $entry): CountryDefinition
    {
        $code = $entry['code'] ?? null;
        $fail = function (string $problem) use ($code): never {
            throw new LogicException('Country ['.(is_string($code) ? $code : '?')."]: {$problem}.");
        };

        if (! is_string($code) || ! preg_match('/^[A-Z]{2}$/', $code)) {
            $fail('code must be two capital letters (ISO 3166-1)');
        }
        if (! is_array($entry['name'] ?? null) || ! is_string($entry['name']['en'] ?? null)) {
            $fail('name needs at least an English text');
        }
        if (! preg_match('/^[A-Z]{3}$/', (string) ($entry['currency'] ?? ''))) {
            $fail('currency must be an ISO 4217 code');
        }
        if (! is_int($entry['currency_decimals'] ?? null) || $entry['currency_decimals'] < 0 || $entry['currency_decimals'] > 4) {
            $fail('currency_decimals must be 0 to 4');
        }
        if (! in_array($entry['date_format'] ?? null, self::DATE_FORMATS, true)) {
            $fail('date_format must be one of '.implode(', ', self::DATE_FORMATS));
        }
        if (! in_array($entry['week_start'] ?? null, self::DAYS, true)) {
            $fail('week_start must be a day (sat..fri)');
        }
        $weekend = $entry['weekend_days'] ?? null;
        if (! is_array($weekend) || array_diff($weekend, self::DAYS) !== [] || count($weekend) > 3) {
            $fail('weekend_days must list up to three days');
        }
        if (! preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', (string) ($entry['fiscal_year_start'] ?? ''))) {
            $fail('fiscal_year_start must look like MM-DD');
        }
        $phone = $entry['phone'] ?? null;
        if (! is_array($phone) || ! preg_match('/^\d{1,4}$/', (string) ($phone['dial'] ?? '')) || ! is_string($phone['national'] ?? null) || ! array_key_exists('trunk', $phone)) {
            $fail('phone needs dial, trunk and national');
        }
        if (! in_array($entry['timezone'] ?? null, DateTimeZone::listIdentifiers(), true)) {
            $fail('timezone must be an IANA name, e.g. Asia/Dhaka');
        }
        $locales = $entry['locales'] ?? null;
        if (! is_array($locales) || ! in_array($entry['default_locale'] ?? null, $locales, true)) {
            $fail('default_locale must be one of its locales');
        }

        return new CountryDefinition(
            code: $code,
            name: $entry['name'],
            currency: $entry['currency'],
            currencyDecimals: $entry['currency_decimals'],
            dateFormat: $entry['date_format'],
            weekStart: $entry['week_start'],
            weekendDays: array_values($weekend),
            fiscalYearStart: $entry['fiscal_year_start'],
            phone: ['dial' => (string) $phone['dial'], 'trunk' => (string) $phone['trunk'], 'national' => $phone['national'], 'example' => (string) ($phone['example'] ?? '')],
            addressFormat: array_values((array) ($entry['address_format'] ?? [])),
            taxProfile: (string) ($entry['tax_profile'] ?? ''),
            dataResidencyRegion: (string) ($entry['data_residency_region'] ?? ''),
            defaultLocale: $entry['default_locale'],
            locales: array_values($locales),
            timezone: $entry['timezone'],
            paymentGateways: array_values((array) ($entry['payment_gateways'] ?? [])),
            merchantGateways: array_values((array) ($entry['merchant_gateways'] ?? [])),
            source: (string) ($entry['source'] ?? ''),
        );
    }
}
