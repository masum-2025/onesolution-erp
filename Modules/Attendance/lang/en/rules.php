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

    'overtime_min_minutes' => [
        'label' => 'Over time counts from (minutes)',
        'description' => 'Minutes worked beyond the shift count as over time only from this many on.',
    ],
    'self_punch' => [
        'label' => 'Employees check in themselves',
        'description' => 'Employees whose login is linked to them may check in and out from their own screen.',
    ],
    'early_punch_minutes' => [
        'label' => 'Earliest check-in before the shift (minutes)',
        'description' => 'A punch this many minutes before the shift starts still counts for that shift; until the same time the next day.',
    ],
    'correction_max_days' => [
        'label' => 'Days back a correction may be asked',
        'description' => 'How many days back a forgotten or wrong punch may still be fixed.',
    ],
    'categories' => [
        'corrections' => 'Corrections',
        'calendar' => 'Calendar',
        'check_in' => 'Check-in',
        'lateness' => 'Lateness',
    ],
];
