<?php

/*
| Diagnostics (php artisan make:erp-module database/module-definitions/diagnostics.php).
| Only the simple lists come from here (tests, referring doctors); test parameters and
| normal ranges, orders, samples, results, verification and reports are written by hand.
*/

$label = fn (string $en, string $bn) => ['en' => $en, 'bn' => $bn];

return [
    'key' => 'diagnostics',
    'name' => $label('Diagnostics', 'ডায়াগনস্টিক'),
    'description' => $label(
        'Laboratory and imaging: tests with normal ranges, orders billed to the patient, barcoded samples, results with flags, verification and signed reports.',
        'ল্যাব ও ইমেজিং: স্বাভাবিক সীমাসহ test, রোগীর বিলে যাওয়া order, barcode-সহ নমুনা, চিহ্নসহ ফল, যাচাই আর সই করা রিপোর্ট।'
    ),
    'requires' => ['patients', 'patient_billing'],
    'icon' => 'scan',
    'entities' => [
        'test' => [
            'label' => $label('Test', 'Test'),
            'plural' => $label('Tests', 'Test-এর তালিকা'),
            'title' => 'name',
            'search' => ['code', 'service_code'],
            'filters' => ['department', 'is_active'],
            'columns' => ['code', 'name'],
            'fields' => [
                'code' => ['type' => 'text', 'required' => true, 'max' => 30, 'unique' => true, 'label' => $label('Code', 'কোড')],
                'name' => ['type' => 'translatable', 'required' => true, 'max' => 150, 'label' => $label('Test name', 'Test-এর নাম')],
                'department' => ['type' => 'enum', 'required' => true, 'label' => $label('Department', 'বিভাগ'), 'options' => [
                    'haematology' => $label('Haematology', 'হেমাটোলজি'),
                    'biochemistry' => $label('Biochemistry', 'বায়োকেমিস্ট্রি'),
                    'microbiology' => $label('Microbiology', 'মাইক্রোবায়োলজি'),
                    'immunology' => $label('Immunology and serology', 'ইমিউনোলজি ও সেরোলজি'),
                    'clinical_pathology' => $label('Clinical pathology (urine, stool)', 'ক্লিনিক্যাল প্যাথলজি (প্রস্রাব, মল)'),
                    'imaging' => $label('Imaging (X-ray, ultrasound)', 'ইমেজিং (এক্স-রে, আল্ট্রাসাউন্ড)'),
                    'cardiology' => $label('Cardiology (ECG, echo)', 'কার্ডিওলজি (ইসিজি, ইকো)'),
                    'other' => $label('Other', 'অন্যান্য'),
                ]],
                'sample_type' => ['type' => 'enum', 'required' => true, 'label' => $label('Sample', 'নমুনা'), 'options' => [
                    'blood' => $label('Blood', 'রক্ত'),
                    'serum' => $label('Serum', 'সিরাম'),
                    'urine' => $label('Urine', 'প্রস্রাব'),
                    'stool' => $label('Stool', 'মল'),
                    'swab' => $label('Swab', 'সোয়াব'),
                    'other' => $label('Other sample', 'অন্য নমুনা'),
                    'none' => $label('No sample (imaging, ECG)', 'নমুনা লাগে না (ইমেজিং, ইসিজি)'),
                ]],
                'service_code' => ['type' => 'text', 'required' => true, 'max' => 30, 'label' => $label('Billing service code (price)', 'বিলের সেবার কোড (দাম)')],
                'turnaround_hours' => ['type' => 'integer', 'label' => $label('Report ready in (hours, empty = usual)', 'রিপোর্ট দিতে লাগে (ঘণ্টা, খালি = সাধারণ)')],
                'is_active' => ['type' => 'boolean', 'label' => $label('In use', 'চালু')],
            ],
        ],
        'referrer' => [
            'label' => $label('Referring doctor', 'Referral ডাক্তার'),
            'plural' => $label('Referring doctors', 'Referral ডাক্তার'),
            'title' => 'name',
            'search' => ['name', 'registration_no', 'mobile'],
            'filters' => ['is_active'],
            'columns' => ['name', 'registration_no'],
            'fields' => [
                'name' => ['type' => 'text', 'required' => true, 'max' => 150, 'label' => $label('Doctor\'s name', 'ডাক্তারের নাম')],
                'registration_no' => ['type' => 'text', 'max' => 30, 'label' => $label('Registration no. (BMDC)', 'নিবন্ধন নম্বর (BMDC)')],
                'mobile' => ['type' => 'text', 'max' => 20, 'label' => $label('Mobile', 'মোবাইল')],
                'commission_percent' => ['type' => 'integer', 'label' => $label('Commission % (empty = usual)', 'কমিশন % (খালি = সাধারণ)')],
                'is_active' => ['type' => 'boolean', 'label' => $label('In use', 'চালু')],
            ],
        ],
    ],
    'rules' => [
        [
            'name' => 'report_turnaround_hours',
            'type' => 'integer',
            'default' => 24,
            'min' => 1,
            'max' => 720,
            'label' => $label('Reports ready in (hours)', 'রিপোর্ট দিতে লাগে (ঘণ্টা)'),
            'description' => $label('When a test does not say, its report is due this many hours after the sample is taken.', 'Test-এ আলাদা না বললে নমুনা নেওয়ার এত ঘণ্টা পরে রিপোর্ট দেওয়ার কথা।'),
        ],
    ],
];
