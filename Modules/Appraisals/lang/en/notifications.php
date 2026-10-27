<?php

return [
    'templates' => [
        'appraisals_opened' => [
            'subject' => '{{ cycle }}: your appraisal has started',
            'body' => 'The appraisal {{ cycle }} at {{ organization }} has started.

Write your goals by {{ due }}.',
            'action' => 'Open my appraisal',
        ],
        'appraisals_your_turn' => [
            'subject' => '{{ cycle }}: {{ employee }} — {{ step }}',
            'body' => 'The appraisal of {{ employee }} ({{ cycle }}, {{ organization }}) waits for you: {{ step }}.

Please do it by {{ due }}.',
            'action' => 'Open the appraisal',
        ],
        'appraisals_reminder' => [
            'subject' => 'Reminder — {{ cycle }}: {{ employee }}',
            'body' => 'The appraisal of {{ employee }} ({{ cycle }}, {{ organization }}) still waits for you: {{ step }}. The last day is {{ due }}.',
            'action' => 'Open the appraisal',
        ],
    ],
    'catalog' => [
        'appraisals_opened' => [
            'name' => 'Appraisal started',
            'description' => 'To each employee of a review cycle just opened.',
        ],
        'appraisals_your_turn' => [
            'name' => 'Appraisal waits for you',
            'description' => 'To whoever acts next on an appraisal (no ratings or comments in the mail).',
        ],
        'appraisals_reminder' => [
            'name' => 'Appraisal reminder',
            'description' => 'Before a step\'s last day, to whoever must act.',
        ],
    ],
];
