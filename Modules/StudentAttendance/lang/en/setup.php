<?php

// Attendance steps on the education setup checklist.
return [
    'steps' => [
        'periods' => [
            'label' => 'Set the periods of the day',
            'missing' => ':count campuses take attendance in each period but have no periods yet.',
        ],
        'holidays' => [
            'label' => 'Add this year\'s holidays',
            'missing' => 'No holidays this year yet: attendance screens will not know the days off.',
        ],
    ],
];
