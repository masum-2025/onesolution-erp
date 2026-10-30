<?php

return [
    'number_prefix' => [
        'label' => 'Project number prefix',
        'description' => 'Projects are numbered PREFIX-YEAR-0001 per company, e.g. PRJ-2026-0001. Capital letters and digits, up to 8.',
    ],
    'max_tasks' => [
        'label' => 'Tasks in one project',
        'description' => 'The most tasks one project may have (a template\'s steps included).',
    ],
    'max_assignees' => [
        'label' => 'People on one task',
        'description' => 'The most people one task may be for.',
    ],
    'overdue_alert' => [
        'label' => 'Tell about overdue tasks',
        'description' => 'Tell a task\'s people once when it passes its due day.',
    ],
];
