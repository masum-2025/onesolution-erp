<?php

// Messages HRM sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'hrm_document_expiring' => [
            'subject' => '{{ count }} employee document(s) to renew at {{ organization }}',
            'body' => 'These employee documents at {{ organization }} expire soon or have expired:

{{ documents }}

Please ask for new copies and upload them to each employee\'s documents.',
            'action' => 'Open HR',
        ],
    ],
    'catalog' => [
        'hrm_document_expiring' => ['name' => 'Employee documents expiring', 'description' => 'To the client people who manage employees, when documents expire soon or have expired.'],
    ],
    'expires_on' => 'expires :date',
    'expired_on' => 'expired :date',
];
