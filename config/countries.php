<?php

/*
|--------------------------------------------------------------------------
| Countries (Phase 6)
|--------------------------------------------------------------------------
|
| One data file per country: currency, date format, week start, weekend,
| fiscal year, phone and address formats, tax profile, data residency,
| languages, timezone and payment gateways. Adding a country is adding a
| file here and running `php artisan countries:sync`.
|
*/

return [

    'path' => env('COUNTRIES_PATH', database_path('data/countries')),

];
