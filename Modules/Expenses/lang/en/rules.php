<?php

return [
    'receipt_required_above' => [
        'label' => 'Receipt needed above',
        'description' => 'An expense above this amount (minor units of the company currency) needs a receipt.',
    ],
    'second_approval_above' => [
        'label' => 'Second approval above',
        'description' => 'A claim above this total (minor units) also needs the finance office to approve it.',
    ],
    'max_claim_age_days' => [
        'label' => 'Oldest expense (days)',
        'description' => 'How many days back an expense may be claimed.',
    ],
    'mileage_rate' => [
        'label' => 'Rate per kilometre',
        'description' => 'Paid per kilometre driven with an own vehicle (minor units); 0 means no mileage claims.',
    ],
    'receipt_max_kb' => [
        'label' => 'Largest receipt (KB)',
        'description' => 'The largest picture or PDF that can be a receipt.',
    ],
    'default_categories' => [
        'label' => 'Usual kinds of expense',
        'description' => 'The kinds "Add the usual ones" adds, with the posting key of each.',
    ],
    'categories' => [
        'expenses' => 'Expenses',
    ],
    'per_diem_rates' => [
        'label' => 'Daily allowance rates',
        'description' => 'The daily allowance per kind of place (minor units a day), for kinds of expense paid by the day.',
    ],
    'advance_max_open' => [
        'label' => 'Most advances at a time',
        'description' => 'At most this much (minor units) in advances owed or asked for by one employee at a time.',
    ],
    'advance_settle_days' => [
        'label' => 'Days to settle an advance',
        'description' => 'An advance is expected to be settled this many days after it is paid, unless a day is given.',
    ],
];
