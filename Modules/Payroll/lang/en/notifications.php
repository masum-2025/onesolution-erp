<?php

// Messages Payroll sends (NotificationCatalog: module notifications). Never an amount.
return [
    'templates' => [
        'payroll_salary_revised' => [
            'subject' => 'Your pay at {{ organization }} is revised',
            'body' => 'Your salary at {{ organization }} is revised from {{ date }}.

You will see the new amount on your payslips.',
            'action' => 'Open my payslips',
        ],
    ],
    'catalog' => [
        'payroll_salary_revised' => ['name' => 'Salary revised', 'description' => 'To an employee when a salary increment sets their new pay (no amount in the message).'],
    ],
];
