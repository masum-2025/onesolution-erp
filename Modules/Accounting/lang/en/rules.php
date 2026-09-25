<?php

return [
    'fiscal_year_start' => [
        'label' => 'Fiscal year start (MM-DD)',
        'description' => 'First day of the financial year.',
    ],
    'journal_approval_above' => [
        'label' => 'Journal approval above',
        'description' => 'Journal entries above this amount need a second approval. Empty = never.',
    ],
    'allow_backdated_entries_days' => [
        'label' => 'Backdated entries allowed (days)',
        'description' => 'How many days back an entry may be dated.',
    ],
];
