<?php

// Rule labels, key = part after "student_attendance.".
return [
    'by_period' => [
        'label' => 'Attendance in each period',
        'description' => 'On: each period\'s teacher takes attendance for their period. Off: the class teacher takes it once a day.',
    ],
    'period_present_share_bp' => [
        'label' => 'Periods needed for a present day',
        'description' => 'With attendance in each period: a student counts as present for the day when present in at least this share of the periods taken (5000 = half).',
    ],
    'late_counts_present' => [
        'label' => 'Late counts as present',
        'description' => 'On: a late day counts among the days present (report cards, monthly register).',
    ],
    'edit_days' => [
        'label' => 'Days a teacher may go back',
        'description' => 'A teacher may take or change attendance for today and this many days before it. Whoever manages attendance may change any day of the session.',
    ],
    'weekend_days' => [
        'label' => 'Weekly days off',
        'description' => 'Days the institution is closed every week. Attendance can still be taken on them; the screen says it is a day off.',
    ],
    'notify_absent' => [
        'label' => 'Tell families of absence',
        'description' => 'On: the family of a student absent today gets a message (SMS costs money), once a day, this many minutes after the attendance is saved so a mistake can be put right first.',
    ],
    'notify_class_skip' => [
        'label' => 'Tell families of a missed class',
        'description' => 'With attendance in each period and absence messages on: also tell the family when a student came in but is absent in a later period.',
    ],
    'low_warn_bp' => [
        'label' => 'Low attendance below',
        'description' => 'Students present on fewer than this share of their school days in the last 30 days show on the home and in the bell (7500 = 75%).',
    ],
    'categories' => [
        'taking' => 'Taking attendance',
        'counting' => 'Counting days',
        'calendar' => 'Days off',
        'families' => 'Families',
    ],
];
