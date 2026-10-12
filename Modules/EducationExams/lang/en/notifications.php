<?php

// Messages exams send (NotificationCatalog: module notifications).
return [
    'templates' => [
        'education_exams_result_published' => [
            'subject' => '{{ exam }} result of {{ student }}',
            'body' => '{{ organization }}: the {{ exam }} result of {{ student }} is published. GPA {{ gpa }} ({{ letter }}). See every subject in the portal.',
            'sms' => '{{ organization }}: {{ student }}, {{ exam }}: GPA {{ gpa }} ({{ letter }}).',
            'action' => 'See the result',
        ],
        'education_exams_result_held' => [
            'subject' => '{{ exam }} result of {{ student }}',
            'body' => '{{ organization }}: the {{ exam }} result of {{ student }} is published. Please contact the office to see it.',
            'sms' => '{{ organization }}: {{ student }}\'s {{ exam }} result is published. Please contact the office.',
            'action' => 'Open the portal',
        ],
    ],
    'catalog' => [
        'education_exams_result_published' => [
            'name' => 'Result published',
            'description' => 'To families when an exam or a final result is published (rule), with the GPA.',
        ],
        'education_exams_result_held' => [
            'name' => 'Result published, held',
            'description' => 'To families of a student whose result is held for fees owed (rule): no grades, contact the office.',
        ],
    ],
];
