<?php

namespace App\Platform\Countries;

use App\Platform\Countries\Models\Country;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleCatalog;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the country data files into the `countries` table and writes each
 * country's rule defaults (weekend, fiscal year, week start, date format,
 * gateways) as platform-level country values. Only values that differ are
 * written, through the rule service (versioned and audited, the data file
 * named as the reason), so running it again changes nothing.
 */
class CountrySync
{
    public function __construct(
        private CountryCatalog $countries,
        private RuleCatalog $rules,
        private RuleService $ruleService,
        private RuleTargets $targets,
    ) {}

    /**
     * @return array{countries: int, rules_written: int, inactive: int}
     */
    public function run(): array
    {
        $written = 0;

        foreach ($this->countries->all() as $country) {
            $this->mirror($country);

            foreach ($country->ruleValues() as $key => $value) {
                if ($this->rules->has($key) && $this->current($key, $country->code) !== $value) {
                    $this->ruleService->set(
                        $this->targets->platform(),
                        $key,
                        RuleMode::Set,
                        $value,
                        "Country data file {$country->code}.php",
                        countryCode: $country->code,
                        trusted: true,
                    );
                    $written++;
                }
            }
        }

        // Kept for organizations that still use it, but no longer offered.
        $inactive = Country::query()->whereNotIn('code', $this->countries->codes())->where('status', 'active')->update(['status' => 'inactive']);

        return ['countries' => count($this->countries->all()), 'rules_written' => $written, 'inactive' => $inactive];
    }

    private function mirror(CountryDefinition $country): void
    {
        DB::transaction(function () use ($country) {
            $row = Country::query()->where('code', $country->code)->lockForUpdate()->first() ?? new Country;

            $row->forceFill([
                'code' => $country->code,
                'currency_code' => $country->currency,
                'currency_decimals' => $country->currencyDecimals,
                'date_format' => $country->dateFormat,
                'week_start' => $country->weekStart,
                'weekend_days' => $country->weekendDays,
                'fiscal_year_start' => $country->fiscalYearStart,
                'phone_format' => $country->phone,
                'address_format' => $country->addressFormat,
                'tax_profile_key' => $country->taxProfile,
                'data_residency_region' => $country->dataResidencyRegion,
                'default_locale' => $country->defaultLocale,
                'locales' => $country->locales,
                'default_timezone' => $country->timezone,
                'payment_gateways' => $country->paymentGateways,
                'merchant_gateways' => $country->merchantGateways,
                'status' => 'active',
            ]);
            $row->putTexts('name', $country->name);
            $row->save();
        });
    }

    private function current(string $key, string $country): mixed
    {
        $row = RuleValue::query()
            ->where('rule_key', $key)
            ->where('scope_type', RuleScope::Platform)
            ->whereNull('scope_id')
            ->where('country_code', $country)
            ->where('status', RuleValueStatus::Active)
            ->where('mode', RuleMode::Set)
            ->orderByDesc('version')
            ->first();

        return $row === null ? '__none__' : $row->value;
    }
}
