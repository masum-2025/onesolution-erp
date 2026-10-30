<?php

return [
    'templates' => [
        'projects_task_assigned' => [
            'subject' => 'New task for you: {{ task }}',
            'body' => '{{ by }} gave you a task in {{ project }} ({{ organization }}): {{ task }}. Due: {{ due }}.',
            'action' => 'Open my tasks',
        ],
        'projects_quote_answered' => [
            'subject' => '{{ other }} {{ answer }} the quotation for {{ project }}',
            'body' => '{{ other }} {{ answer }} the quotation of {{ project }} ({{ organization }}). It is updated in CRM; an accepted one has its draft invoice in Accounting.',
            'action' => 'Open projects',
        ],
        'projects_task_overdue' => [
            'subject' => 'Task past its due day: {{ task }}',
            'body' => 'The task "{{ task }}" in {{ project }} ({{ organization }}) was due on {{ due }} and is not done yet.',
            'action' => 'Open my tasks',
        ],
    ],
    'catalog' => [
        'projects_task_assigned' => [
            'name' => 'Task given to you',
            'description' => 'To each person added to a project task.',
        ],
        'projects_quote_answered' => [
            'name' => 'Client answered a quotation',
            'description' => 'To the person who sent a quotation to a connected client, and the project\'s manager.',
        ],
        'projects_task_overdue' => [
            'name' => 'Task past its due day',
            'description' => 'To a task\'s people, once, when it passes its due day and is not done.',
        ],
    ],
];
