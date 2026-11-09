<?php

return [
    'data_scope' => [
        'label' => 'Data the assistant may use',
        'description' => 'Which records the AI assistant may read to answer.',
        'options' => [
            'own_records' => 'Own records only',
            'branch' => 'Whole branch',
            'company' => 'Whole company',
        ],
    ],

    'monthly_calls' => [
        'label' => 'AI readings per month',
        'description' => 'Most messages the AI may read for the company in a month, all modules together. Past it, work goes on without AI until the next month.',
    ],
    'model' => [
        'label' => 'AI model',
        'description' => 'Haiku is quick and cheap; Sonnet reads harder messages and costs more.',
        'options' => [
            'haiku' => 'Haiku (quick)',
            'sonnet' => 'Sonnet (careful)',
        ],
    ],

    'categories' => [
        'privacy' => 'Privacy',
        'usage' => 'Usage',
    ],
];
