<?php

/*
| Own fields Bangladeshi institutions usually keep (shared by the BD presets).
| Religion is sensitive (seen only with education.view_sensitive, never in
| the portal); blood group shows to guardians and on ID cards.
*/

return [
    [
        'entity' => 'student', 'key' => 'religion', 'type' => 'choice', 'is_sensitive' => true,
        'label' => ['en' => 'Religion', 'bn' => 'ধর্ম'],
        'options' => [
            ['value' => 'islam', 'label' => ['en' => 'Islam', 'bn' => 'ইসলাম']],
            ['value' => 'hinduism', 'label' => ['en' => 'Hinduism', 'bn' => 'হিন্দু']],
            ['value' => 'buddhism', 'label' => ['en' => 'Buddhism', 'bn' => 'বৌদ্ধ']],
            ['value' => 'christianity', 'label' => ['en' => 'Christianity', 'bn' => 'খ্রিস্টান']],
            ['value' => 'other', 'label' => ['en' => 'Other', 'bn' => 'অন্যান্য']],
        ],
    ],
    [
        'entity' => 'student', 'key' => 'blood_group', 'type' => 'choice', 'portal_visible' => true, 'on_documents' => true,
        'label' => ['en' => 'Blood group', 'bn' => 'রক্তের গ্রুপ'],
        'options' => array_map(fn (string $group) => ['value' => $group, 'label' => ['en' => $group, 'bn' => $group]], ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']),
    ],
    [
        'entity' => 'admission', 'key' => 'previous_school', 'type' => 'text',
        'label' => ['en' => 'Previous institution', 'bn' => 'পূর্ববর্তী প্রতিষ্ঠান'],
    ],
    [
        'entity' => 'guardian', 'key' => 'monthly_income', 'type' => 'number', 'is_sensitive' => true,
        'label' => ['en' => 'Monthly income', 'bn' => 'মাসিক আয়'],
    ],
];
