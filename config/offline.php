<?php

/*
|--------------------------------------------------------------------------
| Offline mode (Phase 7)
|--------------------------------------------------------------------------
|
| Keys that sign offline leases, by version: add a new key, point "current"
| at it, and keep the old one until every lease signed with it has expired
| (rule offline_mode.offline_lease_hours). Without its own key, a version
| uses a key derived from APP_KEY. Business numbers are rules, not here.
|
*/

return [

    'lease_keys' => [
        'v1' => env('OFFLINE_LEASE_KEY_V1'),
    ],

    'current_lease_key' => env('OFFLINE_LEASE_KEY_CURRENT', 'v1'),

];
