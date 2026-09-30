<?php

/*
|--------------------------------------------------------------------------
| Tenant databases (Phase 10)
|--------------------------------------------------------------------------
|
| Where a client tree's business data lives. By default everything is in the
| main ("central") database. A group can get a dedicated database, or new
| clients of a region can land in that region's database.
|
| Only the NAME of a database is ever stored in tenant_placements; how to
| reach it (host, user, password) lives here, read from the environment:
|
|   TENANT_DATABASES=eu1,acme
|   TENANT_DB_EU1_URL=pgsql://user:secret@10.0.0.5:5432/erp_eu1
|   TENANT_DB_ACME_DATABASE=erp_acme      (anything not given is copied from
|                                           the main connection)
|   TENANT_REGION_DATABASES=eu=eu1          (new clients in region "eu" -> eu1)
|
| Each name becomes the Laravel connection "tenant_<name>".
|
*/

$names = array_values(array_filter(
    array_map('trim', explode(',', (string) env('TENANT_DATABASES', ''))),
    fn (string $name) => preg_match('/^[a-z][a-z0-9_]{0,30}$/', $name) === 1,
));

$databases = [];
foreach ($names as $name) {
    $prefix = 'TENANT_DB_'.strtoupper($name).'_';
    $databases[$name] = array_filter([
        'url' => env($prefix.'URL'),
        'host' => env($prefix.'HOST'),
        'port' => env($prefix.'PORT'),
        'database' => env($prefix.'DATABASE'),
        'username' => env($prefix.'USERNAME'),
        'password' => env($prefix.'PASSWORD'),
    ], fn ($value) => $value !== null && $value !== '');
}

$regions = [];
foreach (array_filter(array_map('trim', explode(',', (string) env('TENANT_REGION_DATABASES', '')))) as $pair) {
    [$region, $name] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');
    if ($region !== '' && isset($databases[$name])) {
        $regions[strtolower($region)] = $name;
    }
}

return [

    // name => connection overrides (merged over the main connection).
    'databases' => $databases,

    // region => database name, for clients created from now on.
    'regions' => $regions,

    // Migrations of tables that follow their client (business data). Module
    // folders are found automatically: Modules/*/database/migrations/tenant.
    'migration_paths' => [
        database_path('migrations/tenant'),
    ],

];
