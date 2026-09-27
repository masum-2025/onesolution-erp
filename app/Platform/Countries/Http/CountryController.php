<?php

namespace App\Platform\Countries\Http;

use App\Http\Controllers\Controller;
use App\Platform\Countries\CountryCatalog;
use App\Platform\Countries\CountryDefinition;
use Illuminate\Http\JsonResponse;

/**
 * The countries the platform knows, with what they bring by default
 * (currency, language, timezone, week, dates). Reference data, no tenant data.
 */
class CountryController extends Controller
{
    public function __invoke(CountryCatalog $countries): JsonResponse
    {
        $list = array_values(array_map(fn (CountryDefinition $country) => [
            'code' => $country->code,
            'name' => $country->label(),
            'currency' => $country->currency,
            'default_locale' => $country->defaultLocale,
            'locales' => $country->locales,
            'timezone' => $country->timezone,
            'week_start' => $country->weekStart,
            'weekend_days' => $country->weekendDays,
            'date_format' => $country->dateFormat,
            'dial' => $country->phone['dial'],
        ], $countries->all()));

        usort($list, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return response()->json(['data' => $list]);
    }
}
