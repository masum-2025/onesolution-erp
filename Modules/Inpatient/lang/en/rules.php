<?php

// Rule labels, key = part after "inpatient.".
return [
    'bed_day_starts' => [
        'label' => 'A bed day starts',
        'description' => 'At midnight the admission day counts and the discharge day does not; from admission every started 24 hours is a day.',
    ],
    'discharge_requires_clearance' => [
        'label' => 'Discharge only when the bill is paid',
        'description' => 'A patient is discharged only when nothing is due on their bill.',
    ],
    'min_advance' => [
        'label' => 'Advance before admission',
        'description' => 'How much the patient must have on advance (in Patient billing) before admission. Empty = none.',
    ],
    'number_prefix' => [
        'label' => 'Admission number prefix',
        'description' => 'Letters before the admission number, such as IP000123.',
    ],
    'categories' => [
        'charges' => 'Charges',
        'discharge' => 'Discharge',
        'general' => 'General',
    ],
];
