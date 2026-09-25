<?php

return [
    'probation_days' => [
        'label' => 'Probation period (days)',
        'description' => 'Days a new employee stays on probation.',
    ],
    'notice_period_days' => [
        'label' => 'Notice period (days)',
        'description' => 'Days of notice required before leaving the job.',
    ],
    'employee_code_format' => [
        'label' => 'Employee code format',
        'description' => 'Pattern for new employee codes, e.g. EMP-{YYYY}-{SEQ:4}.',
    ],

    'categories' => [
        'employment' => 'Employment',
        'numbering' => 'Numbering',
    ],
];
