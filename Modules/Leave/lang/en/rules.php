<?php

return [
    'year_start_month' => [
        'label' => 'Leave year starts in month',
        'description' => 'The month (1 = January) the leave year begins: yearly leave comes then and what is left is carried over.',
    ],
    'approval_steps' => [
        'label' => 'Approval steps',
        'description' => 'How many different people approve a leave request (1 to 3). The first is the employee\'s manager when the manager may approve leave.',
    ],
    'beyond_balance' => [
        'label' => 'Asking for more than the balance',
        'description' => 'Refuse = no more than the leave left; without pay = the rest is taken as leave without pay.',
        'options' => ['refuse' => 'Refuse', 'unpaid' => 'The rest without pay'],
    ],
    'backdate_days' => [
        'label' => 'Leave asked for after the day (days)',
        'description' => 'Leave may start at most this many days before the day it is asked for (e.g. sick leave asked for on return).',
    ],
    'default_types' => [
        'label' => 'Usual kinds of leave',
        'description' => 'The kinds of leave a company starts from with "Add the usual ones", per country. The company may change them after.',
    ],
    'categories' => [
        'leave_year' => 'Leave year',
        'approval' => 'Asking and approving',
    ],
];
