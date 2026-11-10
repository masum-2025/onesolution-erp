<?php

return [
    'report_turnaround_hours' => [
        'label' => 'Reports ready in (hours)',
        'description' => 'When a test does not say, its report is due this many hours after the sample is taken.',
    ],
    'require_verification' => [
        'label' => 'A second person verifies results',
        'description' => 'Results go out only after someone other than the person who entered them signs them. Off: entering results is enough (very small labs).',
    ],
    'number_prefixes' => [
        'label' => 'Number prefixes',
        'description' => 'Letters before order and sample numbers, e.g. D000001 and S0000001.',
    ],
    'categories' => [
        'reports' => 'Reports',
        'numbering' => 'Numbering',
        'general' => 'General',
    ],
];
