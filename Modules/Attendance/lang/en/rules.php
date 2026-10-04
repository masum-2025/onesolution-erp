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
    'geo_radius_m' => [
        'label' => 'Workplace radius (metres)',
        'description' => 'How far from a workplace\'s point a check-in still counts, for new workplaces.',
    ],
    'geo_max_accuracy_m' => [
        'label' => 'Least accuracy of a phone\'s location (metres)',
        'description' => 'A location the phone is less sure of than this is refused.',
    ],
    'location_retention_days' => [
        'label' => 'Days a check-in\'s location is kept',
        'description' => 'After this many days the point is forgotten; the distance to the workplace stays.',
    ],
    'offline_max_age_hours' => [
        'label' => 'Hours an offline check-in still counts',
        'description' => 'A check-in kept on a phone offline for longer than this is refused when it arrives.',
    ],
    'device_import_max_rows' => [
        'label' => 'Lines per machine file',
        'description' => 'The most lines one attendance machine file may bring in.',
    ],
    'device_import_max_kb' => [
        'label' => 'Machine file size (KB)',
        'description' => 'The largest attendance machine file accepted.',
    ],
    'categories' => [
        'corrections' => 'Corrections',
        'devices' => 'Attendance machines',
        'calendar' => 'Calendar',
        'check_in' => 'Check-in',
        'lateness' => 'Lateness',
    ],
];
