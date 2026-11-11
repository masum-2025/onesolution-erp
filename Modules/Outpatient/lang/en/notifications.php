<?php

// Messages outpatient sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'outpatient_serial_booked' => [
            'subject' => 'Your serial {{ number }} with {{ doctor }}',
            'body' => '{{ organization }}: your serial is {{ number }} with {{ doctor }} on {{ date }} from {{ time }}, {{ chamber }}.',
            'sms' => '{{ organization }}: serial {{ number }}, {{ doctor }}, {{ date }} {{ time }}, {{ chamber }}.',
            'action' => 'See details',
        ],
    ],
    'catalog' => [
        'outpatient_serial_booked' => [
            'name' => 'Doctor\'s serial',
            'description' => 'To the patient when the desk gives a serial (rule "Text the serial to the patient").',
        ],
    ],
];
