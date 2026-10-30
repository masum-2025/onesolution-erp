<?php

return [
    'reminder_days' => [
        'label' => 'Remind before expiry (days)',
        'description' => 'Days before an audit\'s expiry day when the people who manage compliance are told; once more when it has expired. Each reminder goes once.',
    ],
    'expiring_days' => [
        'label' => 'Expiring within (days)',
        'description' => 'An audit shows as "expiring" this many days before its expiry day.',
    ],
    'default_services' => [
        'label' => 'Usual audit services',
        'description' => 'What "Add the usual ones" adds to the list of audit services, with how many months a certificate usually lasts.',
    ],
    'default_audit_types' => [
        'label' => 'Usual kinds of audit',
        'description' => 'What "Add the usual ones" adds to the list of kinds of audit.',
    ],
    'import_max_rows' => [
        'label' => 'Rows in one import',
        'description' => 'The most rows one Excel or CSV file may have.',
    ],
    'request_days_before' => [
        'label' => 'Ask for renewal before expiry (days)',
        'description' => 'From this many days before an audit\'s expiry day the register suggests a renewal request to its provider (and sends it, when sending by itself is on).',
    ],
    'auto_request' => [
        'label' => 'Send renewal requests by themselves',
        'description' => 'At the day above, send the request to a provider connected on the platform without a person pressing the button.',
    ],
    'auto_apply_results' => [
        'label' => 'Take providers\' results in at once',
        'description' => 'Put the dates and result a connected provider sends straight into the register, without someone of yours accepting them first.',
    ],
];
