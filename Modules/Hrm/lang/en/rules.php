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
        'description' => 'Pattern for new employee codes, e.g. EMP-{YYYY}-{SEQ:4}. {UNIT} is the unit\'s code, {YY} the short year.',
    ],
    'employment_types' => [
        'label' => 'Kinds of employment',
        'description' => 'The kinds of employment people can be hired on.',
        'options' => [
            'permanent' => 'Permanent',
            'contract' => 'Contract',
            'part_time' => 'Part-time',
            'intern' => 'Intern',
            'daily_wage' => 'Daily wage',
            'consultant' => 'Consultant',
        ],
    ],
    'national_id_kind' => [
        'label' => 'National id document',
        'description' => 'Which identity document the national id field holds in this country.',
        'options' => [
            'national_id' => 'National ID',
            'nid' => 'NID (Bangladesh)',
            'iqama' => 'Iqama',
            'passport' => 'Passport',
            'ssn' => 'Social security number',
        ],
    ],
    'required_fields' => [
        'label' => 'Required employee details',
        'description' => 'Details that must be filled in when someone is hired.',
        'options' => [
            'date_of_birth' => 'Date of birth',
            'gender' => 'Gender',
            'phone' => 'Phone',
            'email' => 'Email',
            'national_id' => 'National id',
            'address' => 'Address',
            'emergency_contact' => 'Emergency contact',
        ],
    ],
    'document_types' => [
        'label' => 'Kinds of employee documents',
        'description' => 'The kinds of files kept for employees.',
        'options' => [
            'national_id' => 'Copy of the national id',
            'certificate' => 'Certificate',
            'contract' => 'Contract',
            'photo' => 'Photo',
            'cv' => 'CV',
            'medical' => 'Medical record',
            'other' => 'Other',
        ],
    ],
    'document_max_kb' => [
        'label' => 'Largest employee document (KB)',
        'description' => 'Upper size of one uploaded employee document.',
    ],

    'categories' => [
        'employment' => 'Employment',
        'numbering' => 'Numbering',
        'personal' => 'Personal details',
        'documents' => 'Documents',
    ],
];
