<?php

return [
    'rating_scale' => [
        'label' => 'Rating scale',
        'description' => 'The ratings people give, lowest first, each with its name.',
    ],
    'bands' => [
        'label' => 'Result bands',
        'description' => 'Bands by the overall score (0 to 1000): a score gets the highest band it reaches.',
    ],
    'goal_share_percent' => [
        'label' => 'Goals\' share of the score (%)',
        'description' => 'How much goals count in the overall score; competencies count the rest.',
    ],
    'goals_total_100' => [
        'label' => 'Goal weights add up to 100',
        'description' => 'Goals can be sent only when their weights add up to 100.',
    ],
    'self_review' => [
        'label' => 'Self review',
        'description' => 'Employees review themselves before their manager does.',
    ],
    'reminder_days' => [
        'label' => 'Reminder (days before)',
        'description' => 'Reminders go this many days before a step\'s last day, and on the day before.',
    ],
    'default_competencies' => [
        'label' => 'Usual competencies',
        'description' => 'The competencies "Add the usual ones" adds.',
    ],
    'categories' => [
        'appraisals' => 'Appraisals',
    ],
];
