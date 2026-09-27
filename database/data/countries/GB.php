<?php

/*
| United Kingdom. PLACEHOLDER values (weekend, fiscal year, tax profile): to be reviewed
| by an adviser before a client in this country goes live.
*/

return [
    'code' => 'GB',
    'name' => ['en' => 'United Kingdom', 'bn' => 'যুক্তরাজ্য', 'ar' => 'المملكة المتحدة'],
    'currency' => 'GBP',
    'currency_decimals' => 2,
    'date_format' => 'DD/MM/YYYY',
    'week_start' => 'mon',
    'weekend_days' => ['sat', 'sun'],
    'fiscal_year_start' => '04-06',
    'phone' => ['dial' => '44', 'trunk' => '0', 'national' => '7\d{9}', 'example' => '07400123456'],
    'address_format' => ['line1', 'line2', 'city', 'postcode'],
    'tax_profile' => 'gb_vat',
    'data_residency_region' => 'eu',
    'default_locale' => 'en',
    'locales' => ['en'],
    'timezone' => 'Europe/London',
    'payment_gateways' => [],
    'merchant_gateways' => [],
    'source' => 'PLACEHOLDER. Verify with an adviser.',
];
