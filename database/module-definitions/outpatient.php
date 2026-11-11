<?php

/*
| Outpatient (php artisan make:erp-module database/module-definitions/outpatient.php).
| Only the simple lists come from here (doctors, chambers); weekly schedules, a doctor's
| sign-in, serials, fees, the queue screen and consultations are written by hand.
*/

$label = fn (string $en, string $bn) => ['en' => $en, 'bn' => $bn];

return [
    'key' => 'outpatient',
    'name' => $label('Outpatient', 'বহির্বিভাগ'),
    'description' => $label(
        'Doctors\' chambers: weekly schedules, serials with the fee on the patient\'s bill, free follow-ups, the waiting-room screen and the doctor\'s own patient list.',
        'ডাক্তারের চেম্বার: সাপ্তাহিক সময়সূচি, রোগীর বিলে ফিসহ সিরিয়াল, ফ্রি ফলো-আপ, অপেক্ষার ঘরের পর্দা আর ডাক্তারের নিজের রোগীর তালিকা।'
    ),
    'requires' => ['patients', 'patient_billing'],
    'icon' => 'stethoscope',
    'entities' => [
        'doctor' => [
            'label' => $label('Doctor', 'ডাক্তার'),
            'plural' => $label('Doctors', 'ডাক্তার'),
            'title' => 'name',
            'search' => ['registration_no', 'specialty', 'mobile'],
            'filters' => ['is_active'],
            'columns' => ['registration_no', 'specialty'],
            'fields' => [
                'name' => ['type' => 'translatable', 'required' => true, 'max' => 150, 'label' => $label('Doctor\'s name', 'ডাক্তারের নাম')],
                'registration_no' => ['type' => 'text', 'max' => 30, 'label' => $label('Registration no. (BMDC)', 'নিবন্ধন নম্বর (BMDC)')],
                'degrees' => ['type' => 'text', 'max' => 200, 'label' => $label('Degrees', 'ডিগ্রি')],
                'specialty' => ['type' => 'text', 'max' => 150, 'label' => $label('Specialty', 'বিশেষজ্ঞতা')],
                'mobile' => ['type' => 'text', 'max' => 20, 'label' => $label('Mobile', 'মোবাইল')],
                'fee_service_code' => ['type' => 'text', 'required' => true, 'max' => 30, 'label' => $label('Fee: billing service code', 'ফি: বিলের সেবার কোড')],
                'follow_up_service_code' => ['type' => 'text', 'max' => 30, 'label' => $label('Follow-up fee code (empty = same fee)', 'ফলো-আপ ফি-র কোড (খালি = একই ফি)')],
                'is_active' => ['type' => 'boolean', 'label' => $label('In use', 'চালু')],
            ],
        ],
        'chamber' => [
            'label' => $label('Chamber', 'চেম্বার'),
            'plural' => $label('Chambers', 'চেম্বার'),
            'title' => 'name',
            'search' => ['room'],
            'filters' => ['is_active'],
            'columns' => ['room'],
            'fields' => [
                'name' => ['type' => 'text', 'required' => true, 'max' => 100, 'label' => $label('Chamber name', 'চেম্বারের নাম')],
                'room' => ['type' => 'text', 'max' => 30, 'label' => $label('Room', 'রুম')],
                'is_active' => ['type' => 'boolean', 'label' => $label('In use', 'চালু')],
            ],
        ],
    ],
    'rules' => [
        [
            'name' => 'daily_serial_limit',
            'type' => 'integer',
            'default' => 40,
            'min' => 1,
            'max' => 500,
            'label' => $label('Serials a session (usual)', 'এক বেলায় সিরিয়াল (সাধারণ)'),
            'description' => $label('How many patients a doctor sees in one session when the schedule does not say.', 'সময়সূচিতে আলাদা না বললে এক বেলায় একজন ডাক্তার কতজন রোগী দেখেন।'),
        ],
        [
            'name' => 'follow_up_free_days',
            'type' => 'integer',
            'default' => 7,
            'min' => 0,
            'max' => 90,
            'label' => $label('Free follow-up within (days)', 'এত দিনের মধ্যে ফলো-আপ ফ্রি'),
            'description' => $label('Coming back to the same doctor within this many days of a finished visit costs nothing. 0 = never free.', 'শেষ হওয়া দেখানোর এত দিনের মধ্যে একই ডাক্তারের কাছে এলে ফি লাগে না। ০ = কখনো ফ্রি নয়।'),
        ],
        [
            'name' => 'serial_sms',
            'type' => 'boolean',
            'default' => false,
            'label' => $label('Text the serial to the patient', 'রোগীকে সিরিয়াল SMS করুন'),
            'description' => $label('When a serial is given, the patient gets an SMS with the doctor, date and number (needs SMS to be on).', 'সিরিয়াল দিলে রোগী ডাক্তার, তারিখ আর নম্বরসহ SMS পান (SMS চালু থাকতে হবে)।'),
        ],
        [
            'name' => 'prescription_language',
            'type' => 'enum',
            'default' => 'bn',
            'options' => ['bn' => $label('Bangla', 'বাংলা'), 'en' => $label('English', 'ইংরেজি'), 'both' => $label('Both', 'দুটোই')],
            'label' => $label('Prescription language', 'প্রেসক্রিপশনের ভাষা'),
            'description' => $label('The language instructions are printed in on prescriptions.', 'প্রেসক্রিপশনে নির্দেশনা কোন ভাষায় ছাপা হবে।'),
        ],
    ],
];
