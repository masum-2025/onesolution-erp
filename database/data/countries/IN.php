<?php

/*
| India. PLACEHOLDER values (weekend, fiscal year, tax profile): to be reviewed
| by an adviser before a client in this country goes live.
*/

return [
    'code' => 'IN',
    'name' => ['en' => 'India', 'bn' => 'ভারত', 'ar' => 'الهند'],
    'currency' => 'INR',
    'currency_decimals' => 2,
    'date_format' => 'DD/MM/YYYY',
    'week_start' => 'mon',
    'weekend_days' => ['sun'],
    'fiscal_year_start' => '04-01',
    'phone' => ['dial' => '91', 'trunk' => '0', 'national' => '[6-9]\d{9}', 'example' => '09876543210'],
    'address_format' => ['line1', 'line2', 'city', 'state', 'postcode'],
    'tax_profile' => 'in_gst',
    'data_residency_region' => 'in',
    'default_locale' => 'en',
    'locales' => ['en'],
    'timezone' => 'Asia/Kolkata',
    'payment_gateways' => [],
    'merchant_gateways' => [],
    'source' => 'PLACEHOLDER. Verify with an adviser.',
];
