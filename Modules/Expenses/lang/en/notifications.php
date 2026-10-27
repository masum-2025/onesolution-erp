<?php

return [
    'templates' => [
        'expenses_submitted' => [
            'subject' => 'Expense claim {{ number }} of {{ employee }} to approve',
            'body' => '{{ employee }} at {{ organization }} sent expense claim {{ number }} ({{ amount }}).

Open it to see the receipts and decide.',
            'action' => 'Open claims to approve',
        ],
        'expenses_decided' => [
            'subject' => 'Your expense claim {{ number }}: {{ decision }}',
            'body' => 'Your expense claim {{ number }} ({{ amount }}) at {{ organization }} is {{ decision }}.

{{ note }}',
            'action' => 'Open my expenses',
        ],
        'expenses_paid' => [
            'subject' => 'Your expense claim {{ number }} is paid',
            'body' => 'Your expense claim {{ number }} ({{ amount }}) at {{ organization }} is paid back.',
            'action' => 'Open my expenses',
        ],
        'expenses_advance_submitted' => [
            'subject' => 'Expense advance {{ number }} of {{ employee }} to approve',
            'body' => '{{ employee }} at {{ organization }} asks for an expense advance {{ number }} ({{ amount }}).

Open it to decide.',
            'action' => 'Open advances',
        ],
        'expenses_advance_decided' => [
            'subject' => 'Your expense advance {{ number }}: {{ decision }}',
            'body' => 'Your expense advance {{ number }} ({{ amount }}) at {{ organization }}: {{ decision }}.

{{ note }}',
            'action' => 'Open my expenses',
        ],
    ],
    'catalog' => [
        'expenses_submitted' => [
            'name' => 'Expense claim to approve',
            'description' => 'To the people who may decide the next step of a claim.',
        ],
        'expenses_decided' => [
            'name' => 'Expense claim decided',
            'description' => 'To the employee, when their claim is approved or rejected.',
        ],
        'expenses_paid' => [
            'name' => 'Expense claim paid',
            'description' => 'To the employee, when their claim is paid back.',
        ],
        'expenses_advance_submitted' => [
            'name' => 'Expense advance to approve',
            'description' => 'To the people who may decide the next step of an advance.',
        ],
        'expenses_advance_decided' => [
            'name' => 'Expense advance decided or paid',
            'description' => 'To the employee, when their advance is approved, rejected or paid out.',
        ],
    ],
];
