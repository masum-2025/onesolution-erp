<?php

// Messages Attendance sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'attendance_absent_streak' => [
            'subject' => '{{ count }} employee(s) absent many days in a row at {{ organization }}',
            'body' => 'These employees at {{ organization }} have been absent for many working days in a row, without leave:

{{ people }}

Please contact them. If the absence goes on, follow your policy and the law (for example a written notice) before taking any step.',
            'action' => 'Open attendance',
        ],
    ],
    'catalog' => [
        'attendance_absent_streak' => ['name' => 'Absent many days in a row', 'description' => 'To the client people who manage employees, when someone has been absent for the set number of working days in a row.'],
    ],
    'since' => ':days working days, since :date',
];
