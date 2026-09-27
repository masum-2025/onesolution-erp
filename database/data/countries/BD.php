<?php

/*
| Bangladesh. Country facts are data: `php artisan countries:sync` mirrors this
| file into the `countries` table and writes the country's rule defaults
| (weekend, fiscal year, week start, date format, gateways) as audited,
| platform-level country values. Legal and tax facts need an adviser's review.
*/

return [
    'code' => 'BD',
    'name' => ['en' => 'Bangladesh', 'bn' => 'বাংলাদেশ', 'ar' => 'بنغلاديش'],
    'currency' => 'BDT',
    'currency_decimals' => 2,
    'date_format' => 'DD/MM/YYYY',
    'week_start' => 'sat',
    'weekend_days' => ['fri'],
    'fiscal_year_start' => '07-01',
    'phone' => ['dial' => '880', 'trunk' => '0', 'national' => '1[3-9]\d{8}', 'example' => '01712345678'],
    'address_format' => ['line1', 'line2', 'area', 'city', 'postcode'],
    'tax_profile' => 'bd_vat',
    'data_residency_region' => 'bd',
    'default_locale' => 'bn',
    'locales' => ['bn', 'en'],
    'timezone' => 'Asia/Dhaka',
    'payment_gateways' => ['sslcommerz'],
    'merchant_gateways' => ['sslcommerz'],
    'source' => 'Labour Act 2006 s.103 (weekly holiday); fiscal year July-June; VAT and Supplementary Duty Act 2012. Verify with an adviser.',
];
