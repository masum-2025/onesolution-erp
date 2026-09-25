<?php

return [
    'late_grace_minutes' => [
        'label' => 'Late grace period (minutes)',
        'description' => 'Minutes after the start time before a check-in counts as late.',
    ],
    'weekend_days' => [
        'label' => 'Weekend days',
        'description' => 'Days of the week that are not working days.',
        'options' => [
            'sat' => 'Saturday',
            'sun' => 'Sunday',
            'mon' => 'Monday',
            'tue' => 'Tuesday',
            'wed' => 'Wednesday',
            'thu' => 'Thursday',
            'fri' => 'Friday',
        ],
    ],
    'half_day_after_minutes' => [
        'label' => 'Half day after (minutes late)',
        'description' => 'A check-in this many minutes late counts as a half day.',
    ],
    'geo_fence_required' => [
        'label' => 'Location check required',
        'description' => 'Check-in is only allowed inside the workplace area.',
    ],

    'categories' => [
        'calendar' => 'Calendar',
        'check_in' => 'Check-in',
        'lateness' => 'Lateness',
    ],
];
