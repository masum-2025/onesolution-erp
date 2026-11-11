<?php

// Rule labels, key = part after "outpatient.".
return [
    'daily_serial_limit' => [
        'label' => 'Serials a session (usual)',
        'description' => 'How many patients a doctor sees in one session when the schedule does not say.',
    ],
    'follow_up_free_days' => [
        'label' => 'Free follow-up within (days)',
        'description' => 'Coming back to the same doctor within this many days of a finished visit costs nothing. 0 = never free.',
    ],
    'serial_sms' => [
        'label' => 'Text the serial to the patient',
        'description' => 'When a serial is given, the patient gets an SMS with the doctor, date and number (needs SMS to be on).',
    ],
    'prescription_language' => [
        'label' => 'Prescription language',
        'description' => 'The language instructions are printed in on prescriptions.',
    ],
    'categories' => [
        'serials' => 'Serials',
        'fees' => 'Fees',
        'prescriptions' => 'Prescriptions',
        'general' => 'General',
    ],
];
