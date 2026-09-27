<?php

/*
| Malaysia. PLACEHOLDER values (weekend, fiscal year, tax profile): to be reviewed
| by an adviser before a client in this country goes live.
*/

return [
    'code' => 'MY',
    'name' => ['en' => 'Malaysia', 'bn' => 'মালয়েশিয়া', 'ar' => 'ماليزيا'],
    'currency' => 'MYR',
    'currency_decimals' => 2,
    'date_format' => 'DD/MM/YYYY',
    'week_start' => 'mon',
    'weekend_days' => ['sat', 'sun'],
    'fiscal_year_start' => '01-01',
    'phone' => ['dial' => '60', 'trunk' => '0', 'national' => '1\d{8,9}', 'example' => '0123456789'],
    'address_format' => ['line1', 'line2', 'postcode', 'city', 'state'],
    'tax_profile' => 'my_sst',
    'data_residency_region' => 'my',
    'default_locale' => 'en',
    'locales' => ['en'],
    'timezone' => 'Asia/Kuala_Lumpur',
    'payment_gateways' => [],
    'merchant_gateways' => [],
    'source' => 'PLACEHOLDER. Verify with an adviser.',
];
