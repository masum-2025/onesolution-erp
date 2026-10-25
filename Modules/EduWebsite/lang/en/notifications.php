<?php

// Messages the website sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'edu_website_enquiry_received' => [
            'subject' => 'New admission enquiry: {{ level }}',
            'body' => 'Someone asked about admission ({{ level }}) on the website of {{ organization }}.

Open the enquiries to call them back.',
            'action' => 'Open enquiries',
        ],
    ],
    'catalog' => [
        'edu_website_enquiry_received' => ['name' => 'Admission enquiry received', 'description' => 'To the people who handle enquiries, when one comes from the website (no names or numbers).'],
    ],
];
