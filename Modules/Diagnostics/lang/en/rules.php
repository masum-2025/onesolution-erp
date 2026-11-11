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
    'referral_commission_percent' => [
        'label' => 'Referring doctor commission (%)',
        'description' => 'Of what a referred order\'s tests come to after discounts, without tax, once the bill is final. A doctor\'s own percent comes first.',
    ],
    'report_link_hours' => [
        'label' => 'Report link works for (hours)',
        'description' => 'A link to a verified report opens without signing in for this long; after that it says the link is old.',
    ],
    'send_report_sms' => [
        'label' => 'Text the report link when ready',
        'description' => 'As soon as every test of an order is verified, the patient gets an SMS with the report link (needs SMS on and a mobile number).',
    ],
    'categories' => [
        'reports' => 'Reports',
        'numbering' => 'Numbering',
        'commission' => 'Commission',
        'general' => 'General',
    ],
];
