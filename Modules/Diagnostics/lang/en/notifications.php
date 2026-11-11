<?php

// Messages diagnostics sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'diagnostics_report_link' => [
            'subject' => 'Your lab report {{ number }} is ready',
            'body' => '{{ organization }}: your lab report {{ number }} is ready. Open it here for the next {{ hours }} hours.',
            'sms' => '{{ organization }}: lab report {{ number }} is ready. See it ({{ hours }} h): {{ link }}',
            'action' => 'See the report',
        ],
    ],
    'catalog' => [
        'diagnostics_report_link' => [
            'name' => 'Lab report link',
            'description' => 'To the patient when the desk sends the report link, or as soon as every test is verified (rule).',
        ],
    ],
];
