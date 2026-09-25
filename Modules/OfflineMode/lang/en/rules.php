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

    'categories' => [
        'lease' => 'Offline access',
        'payments' => 'Payments',
        'storage' => 'Storage',
    ],
];
