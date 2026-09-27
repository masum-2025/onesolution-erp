<?php

namespace App\Platform\Countries;

/**
 * One country's facts, from its data file (database/data/countries/XX.php).
 */
final readonly class CountryDefinition
{
    /**
     * @param  array<string, string>  $name  Per language.
     * @param  list<string>  $weekendDays  sat..fri
     * @param  array{dial: string, trunk: string, national: string, example: string}  $phone
     * @param  list<string>  $addressFormat  Address lines in the order people write them.
     * @param  list<string>  $locales  Languages used there.
     * @param  list<string>  $paymentGateways  For paying us (platform billing).
     * @param  list<string>  $merchantGateways  For clients' own merchant accounts.
     */
    public function __construct(
        public string $code,
        public array $name,
        public string $currency,
        public int $currencyDecimals,
        public string $dateFormat,
        public string $weekStart,
        public array $weekendDays,
        public string $fiscalYearStart,
        public array $phone,
        public array $addressFormat,
        public string $taxProfile,
        public string $dataResidencyRegion,
        public string $defaultLocale,
        public array $locales,
        public string $timezone,
        public array $paymentGateways,
        public array $merchantGateways,
        public string $source,
    ) {}

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->name[$locale] ?? $this->name[config('app.fallback_locale')] ?? $this->code;
    }

    /**
     * The country's values for rules (rule key => value), written by countries:sync.
     *
     * @return array<string, mixed>
     */
    public function ruleValues(): array
    {
        $values = [];
        foreach (CountryCatalog::RULES as $field => $rule) {
            $values[$rule] = $this->{$field};
        }

        return $values;
    }
}
