<?php

// Messages course registration sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'course_registration_seat_offered' => [
            'subject' => 'You now have a seat in {{ subject }}',
            'body' => 'A seat in {{ subject }} ({{ session }}) at {{ organization }} became free and is now yours. Check your registration.',
            'sms' => '{{ organization }}: you now have a seat in {{ subject }} ({{ session }}).',
            'action' => 'Open my registration',
        ],
    ],
    'catalog' => [
        'course_registration_seat_offered' => ['name' => 'Seat from the waiting list', 'description' => 'To a student who moved up from a waiting list into a freed seat.'],
    ],
];
