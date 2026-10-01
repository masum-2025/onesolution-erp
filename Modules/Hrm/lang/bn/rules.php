<?php

return [
    'probation_days' => [
        'label' => 'শিক্ষানবিশকাল (দিন)',
        'description' => 'নতুন কর্মী কত দিন শিক্ষানবিশ থাকবেন।',
    ],
    'notice_period_days' => [
        'label' => 'নোটিশের মেয়াদ (দিন)',
        'description' => 'চাকরি ছাড়ার আগে কত দিনের নোটিশ দিতে হবে।',
    ],
    'employee_code_format' => [
        'label' => 'কর্মী কোডের ধরন',
        'description' => 'নতুন কর্মী কোডের নমুনা, যেমন EMP-{YYYY}-{SEQ:4}। {UNIT} মানে ইউনিটের কোড, {YY} মানে বছরের শেষ দুই অঙ্ক।',
    ],
    'employment_types' => [
        'label' => 'চাকরির ধরন',
        'description' => 'কোন কোন ধরনে কর্মী নিয়োগ দেওয়া যাবে।',
        'options' => [
            'permanent' => 'স্থায়ী',
            'contract' => 'চুক্তিভিত্তিক',
            'part_time' => 'খণ্ডকালীন',
            'intern' => 'শিক্ষানবিশ (ইন্টার্ন)',
            'daily_wage' => 'দৈনিক মজুরি',
            'consultant' => 'পরামর্শক',
        ],
    ],
    'national_id_kind' => [
        'label' => 'জাতীয় পরিচয়ের কাগজ',
        'description' => 'এই দেশে জাতীয় পরিচয়ের ঘরে কোন কাগজের নম্বর থাকবে।',
        'options' => [
            'national_id' => 'জাতীয় পরিচয়পত্র',
            'nid' => 'এনআইডি (বাংলাদেশ)',
            'iqama' => 'ইকামা',
            'passport' => 'পাসপোর্ট',
            'ssn' => 'সোশ্যাল সিকিউরিটি নম্বর',
        ],
    ],
    'required_fields' => [
        'label' => 'কর্মীর আবশ্যিক তথ্য',
        'description' => 'নিয়োগের সময় যে তথ্যগুলো অবশ্যই দিতে হবে।',
        'options' => [
            'date_of_birth' => 'জন্মতারিখ',
            'gender' => 'লিঙ্গ',
            'phone' => 'ফোন',
            'email' => 'ইমেইল',
            'national_id' => 'জাতীয় পরিচয় নম্বর',
            'address' => 'ঠিকানা',
            'emergency_contact' => 'জরুরি যোগাযোগ',
        ],
    ],
    'document_types' => [
        'label' => 'কর্মীর কাগজপত্রের ধরন',
        'description' => 'কর্মীদের কোন কোন ধরনের ফাইল রাখা হবে।',
        'options' => [
            'national_id' => 'জাতীয় পরিচয়পত্রের কপি',
            'certificate' => 'সনদ',
            'contract' => 'চুক্তিপত্র',
            'photo' => 'ছবি',
            'cv' => 'জীবনবৃত্তান্ত',
            'medical' => 'চিকিৎসা-সংক্রান্ত কাগজ',
            'other' => 'অন্যান্য',
        ],
    ],
    'document_max_kb' => [
        'label' => 'কর্মীর কাগজের সর্বোচ্চ আকার (কেবি)',
        'description' => 'একটি আপলোড করা কাগজের সর্বোচ্চ আকার।',
    ],

    'categories' => [
        'employment' => 'চাকরি',
        'numbering' => 'নম্বর নির্ধারণ',
        'personal' => 'ব্যক্তিগত তথ্য',
        'documents' => 'কাগজপত্র',
    ],
];
