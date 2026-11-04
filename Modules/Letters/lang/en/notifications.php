<?php

return [
    'templates' => [
        'letters_issued' => [
            'subject' => 'A letter for you: {{ title }}',
            'body' => '{{ organization }} has issued you a letter: {{ title }}.

Open it to read, print or save it.',
            'action' => 'Open my letters',
        ],
    ],
    'catalog' => [
        'letters_issued' => [
            'name' => 'Letter issued',
            'description' => 'To the employee, when a letter is issued and shared with them (its name only).',
        ],
    ],
];
