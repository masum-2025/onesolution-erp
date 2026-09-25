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

    'categories' => [
        'privacy' => 'Privacy',
    ],
];
