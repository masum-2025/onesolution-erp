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

    'custom_fields_max' => [
        'label' => 'Extra employee fields (most)',
        'description' => 'How many extra fields a company can ask about its employees.',
    ],
    'import_max_rows' => [
        'label' => 'Employees per import file (most)',
        'description' => 'Rows one CSV file may hold. Split larger lists into several files.',
    ],
    'import_max_kb' => [
        'label' => 'Import file size (KB, most)',
        'description' => 'Largest CSV file accepted for importing employees.',
    ],
    'max_reporting_depth' => [
        'label' => 'Longest reporting line',
        'description' => 'How many managers may sit above anyone. Keeps the org chart readable.',
    ],
    'document_expiry_alert_days' => [
        'label' => 'Document expiry reminders (days before)',
        'description' => 'HR is told this many days before an employee document expires, once for each number. Empty = no reminders.',
    ],
    'categories' => [
        'structure' => 'Reporting lines',
        'employment' => 'Employment',
        'numbering' => 'Numbering',
        'personal' => 'Personal details',
        'documents' => 'Documents',
        'import' => 'Importing employees',
    ],
];
