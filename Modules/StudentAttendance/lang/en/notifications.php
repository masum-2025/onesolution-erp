<?php

// Messages attendance sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'student_attendance_absent' => [
            'subject' => '{{ student }} is absent today',
            'body' => '{{ organization }}: {{ student }} was marked absent today ({{ date }}). If this is a mistake or you know the reason, please tell the school.',
            'sms' => '{{ organization }}: {{ student }} is absent today ({{ date }}). Please tell the school the reason.',
            'action' => 'See attendance',
        ],
        'student_attendance_skipped' => [
            'subject' => '{{ student }} missed a class today',
            'body' => '{{ organization }}: {{ student }} was in school today ({{ date }}) but absent in {{ period }} {{ subject }}.',
            'sms' => '{{ organization }}: {{ student }} was absent in {{ period }} {{ subject }} today after coming to school.',
            'action' => 'See attendance',
        ],
    ],
    'catalog' => [
        'student_attendance_absent' => [
            'name' => 'Student absent today',
            'description' => 'To families when their child is marked absent today (rule), once a day.',
        ],
        'student_attendance_skipped' => [
            'name' => 'Student missed a class',
            'description' => 'To families when their child came in but missed a later period (attendance by period, rule).',
        ],
    ],
];
