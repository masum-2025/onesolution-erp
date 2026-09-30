<?php

/*
|--------------------------------------------------------------------------
| Analytics export (Phase 10-3)
|--------------------------------------------------------------------------
|
| Heavy analytics run in a separate store, fed by queued jobs
| (analytics:export, every 15 minutes). Only what analytics needs leaves the
| platform: ids of partners and organizations, what happened and when. No
| people, addresses, values or free text.
|
*/

return [

    // none | jsonl (files on the local disk, for a collector) | clickhouse
    'driver' => env('ANALYTICS_DRIVER', 'none'),

    'batch' => 1000,

    // Rows younger than this wait for the next run, so one still being saved
    // with an earlier time is not skipped.
    'lag_seconds' => 60,

    'jsonl' => [
        'disk' => env('ANALYTICS_JSONL_DISK', 'local'),
        'path' => 'analytics',
    ],

    // ClickHouse over HTTP(S): INSERT ... FORMAT JSONEachRow into
    // "<database>.<dataset>" (create the tables there first, see README).
    'clickhouse' => [
        'url' => env('ANALYTICS_CLICKHOUSE_URL'),
        'database' => env('ANALYTICS_CLICKHOUSE_DATABASE', 'onesolution'),
        'username' => env('ANALYTICS_CLICKHOUSE_USERNAME', 'default'),
        'password' => env('ANALYTICS_CLICKHOUSE_PASSWORD', ''),
        'timeout' => 30,
    ],

];
