<?php

return [
    'offline_lease_hours' => [
        'label' => 'Offline access period (hours)',
        'description' => 'How long a device may work offline before it must reconnect.',
    ],
    'max_cached_records' => [
        'label' => 'Maximum records kept offline',
        'description' => 'Upper limit of records stored on a device.',
    ],
    'allow_offline_payments' => [
        'label' => 'Allow payments offline',
        'description' => 'Allow recording payments while offline.',
    ],

    'sync_batch_max' => [
        'label' => 'Changes per sync',
        'description' => 'How many offline changes a device sends in one sync; the rest follow in the next.',
    ],
    'quarantine_days' => [
        'label' => 'Days to decide on held changes',
        'description' => 'Changes from a revoked device or person wait this long for a decision, then are discarded.',
    ],

    'categories' => [
        'sync' => 'Sync',
        'lease' => 'Offline access',
        'payments' => 'Payments',
        'storage' => 'Storage',
    ],
];
