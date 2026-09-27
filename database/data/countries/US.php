<?php

/*
| United States. PLACEHOLDER values (weekend, fiscal year, tax profile): to be reviewed
| by an adviser before a client in this country goes live.
*/

return [
    'code' => 'US',
    'name' => ['en' => 'United States', 'bn' => 'মার্কিন যুক্তরাষ্ট্র', 'ar' => 'الولايات المتحدة'],
    'currency' => 'USD',
    'currency_decimals' => 2,
    'date_format' => 'MM/DD/YYYY',
    'week_start' => 'sun',
    'weekend_days' => ['sat', 'sun'],
    'fiscal_year_start' => '01-01',
    'phone' => ['dial' => '1', 'trunk' => '', 'national' => '[2-9]\d{2}[2-9]\d{6}', 'example' => '2025550123'],
    'address_format' => ['line1', 'line2', 'city', 'state', 'postcode'],
    'tax_profile' => 'us_sales_tax',
    'data_residency_region' => 'us',
    'default_locale' => 'en',
    'locales' => ['en'],
    'timezone' => 'America/New_York',
    'payment_gateways' => [],
    'merchant_gateways' => [],
    'source' => 'PLACEHOLDER. Verify with an adviser.',
];
