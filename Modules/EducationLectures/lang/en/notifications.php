<?php

// Messages class lectures sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'education_lectures_published' => [
            'subject' => 'New lecture in {{ subject }}: {{ title }}',
            'body' => '{{ teacher }} posted "{{ title }}" for {{ subject }} at {{ organization }}.',
            'sms' => '{{ organization }}: new lecture in {{ subject }}.',
            'action' => 'Open the lecture',
        ],
    ],
    'catalog' => [
        'education_lectures_published' => [
            'name' => 'New lecture',
            'description' => 'To students and guardians with a login when a lecture of their section is published.',
        ],
    ],
];
