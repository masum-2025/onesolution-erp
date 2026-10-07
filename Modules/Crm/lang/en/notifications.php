<?php

// Messages CRM sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'crm_follow_up_due' => [
            'subject' => '{{ count }} follow-up(s) due at {{ organization }}',
            'body' => 'Your follow-ups due soon at {{ organization }}:

{{ tasks }}

Open your follow-ups to call, visit or write.',
            'action' => 'Open follow-ups',
        ],
    ],
    'catalog' => [
        'crm_follow_up_due' => [
            'name' => 'Follow-ups due',
            'description' => 'To the person a CRM follow-up is given to, shortly before it is due.',
        ],
    ],
];
