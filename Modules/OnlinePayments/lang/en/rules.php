<?php

return [
    'gateways' => [
        'label' => 'Payment gateways clients may connect',
        'description' => 'Which gateways a company may connect its own account for, in its country.',
    ],
    'live_mode_allowed' => [
        'label' => 'Live (real money) accounts allowed',
        'description' => 'Off: only sandbox (test) accounts can be connected. Set by the platform.',
    ],
    'single_approver_wait_hours' => [
        'label' => 'Wait before a change takes effect without a second approver (hours)',
        'description' => 'When nobody else can approve a gateway account change, it takes effect after this many hours. Owners are told at once.',
    ],
    'payment_expiry_minutes' => [
        'label' => 'Unfinished payment expires after (minutes)',
        'description' => 'A payment the customer did not finish on the gateway page is given up after this long.',
    ],
];
