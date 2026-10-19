<?php

// Messages Leave sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'leave_requested' => [
            'subject' => '{{ employee }} asks for {{ type }}',
            'body' => '{{ employee }} at {{ organization }} asks for {{ type }}: {{ dates }} ({{ days }} day(s)).

Open the request to approve or reject it.',
            'action' => 'Open leave requests',
        ],
        'leave_decided' => [
            'subject' => 'Your {{ type }}: {{ decision }}',
            'body' => 'Your request for {{ type }} at {{ organization }}, {{ dates }} ({{ days }} day(s)), is {{ decision }}.

{{ note }}',
            'action' => 'Open my leave',
        ],
    ],
    'catalog' => [
        'leave_requested' => ['name' => 'Leave asked for', 'description' => 'To the people who may approve it, when an employee asks for leave.'],
        'leave_decided' => ['name' => 'Leave decided', 'description' => 'To the employee, when their leave is approved or rejected.'],
    ],
];
