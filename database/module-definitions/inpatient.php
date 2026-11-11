<?php

/*
| Inpatient (php artisan make:erp-module database/module-definitions/inpatient.php).
| Only the simple list comes from here (wards and cabins); beds and their state,
| admissions, bed moves, daily bed charges, rounds, nursing notes and discharge are
| written by hand.
*/

$label = fn (string $en, string $bn) => ['en' => $en, 'bn' => $bn];

return [
    'key' => 'inpatient',
    'name' => $label('Inpatient', 'অন্তর্বিভাগ'),
    'description' => $label(
        'Admitted patients: wards and beds, admission with an advance, the bed board, bed moves, daily bed charges on the bill, rounds, nursing notes and discharge.',
        'ভর্তি রোগী: ওয়ার্ড ও বেড, অগ্রিমসহ ভর্তি, বেডের অবস্থা, বেড বদল, বিলে প্রতিদিনের বেড ভাড়া, ডাক্তারের round, নার্সের নোট আর ছাড়পত্র।'
    ),
    'requires' => ['patients', 'patient_billing'],
    'icon' => 'bed',
    'entities' => [
        'ward' => [
            'label' => $label('Ward or cabin', 'ওয়ার্ড বা কেবিন'),
            'plural' => $label('Wards and cabins', 'ওয়ার্ড ও কেবিন'),
            'title' => 'name',
            'search' => [],
            'filters' => ['kind', 'is_active'],
            'columns' => [],
            'fields' => [
                'name' => ['type' => 'translatable', 'required' => true, 'max' => 100, 'label' => $label('Name', 'নাম')],
                'kind' => ['type' => 'enum', 'required' => true, 'label' => $label('Kind', 'ধরন'), 'options' => [
                    'general' => $label('General ward', 'সাধারণ ওয়ার্ড'),
                    'cabin' => $label('Cabin', 'কেবিন'),
                    'icu' => $label('ICU', 'আইসিইউ'),
                    'hdu' => $label('HDU', 'এইচডিইউ'),
                    'nicu' => $label('NICU', 'এনআইসিইউ'),
                    'other' => $label('Other', 'অন্যান্য'),
                ]],
                'is_active' => ['type' => 'boolean', 'label' => $label('In use', 'চালু')],
            ],
        ],
    ],
    'rules' => [
        [
            'name' => 'bed_day_starts',
            'type' => 'enum',
            'default' => 'midnight',
            'options' => ['midnight' => $label('At midnight', 'রাত ১২টায়'), 'admission_time' => $label('Every 24 hours from admission', 'ভর্তির সময় থেকে প্রতি ২৪ ঘণ্টায়')],
            'label' => $label('A bed day starts', 'বেডের দিন শুরু হয়'),
            'description' => $label('At midnight the admission day counts and the discharge day does not; from admission every started 24 hours is a day.', 'রাত ১২টায় হলে ভর্তির দিন গোনা হয়, ছাড়ার দিন নয়; ভর্তির সময় থেকে হলে প্রতিটি শুরু হওয়া ২৪ ঘণ্টা একদিন।'),
        ],
        [
            'name' => 'discharge_requires_clearance',
            'type' => 'boolean',
            'default' => true,
            'label' => $label('Discharge only when the bill is paid', 'বিল পরিশোধ হলে তবেই ছাড়পত্র'),
            'description' => $label('A patient is discharged only when nothing is due on their bill.', 'বিলে কিছু বাকি না থাকলে তবেই রোগীকে ছাড়পত্র দেওয়া হয়।'),
        ],
    ],
];
