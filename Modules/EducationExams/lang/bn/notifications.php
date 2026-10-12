<?php

// Messages exams send (NotificationCatalog: module notifications).
return [
    'templates' => [
        'education_exams_result_published' => [
            'subject' => '{{ student }}-এর {{ exam }}-এর ফলাফল',
            'body' => '{{ organization }}: {{ student }}-এর {{ exam }}-এর ফলাফল প্রকাশিত হয়েছে। জিপিএ {{ gpa }} ({{ letter }})। প্রতিটি বিষয়ের ফল পোর্টালে দেখুন।',
            'sms' => '{{ organization }}: {{ student }}, {{ exam }}: জিপিএ {{ gpa }} ({{ letter }})।',
            'action' => 'ফলাফল দেখুন',
        ],
        'education_exams_result_held' => [
            'subject' => '{{ student }}-এর {{ exam }}-এর ফলাফল',
            'body' => '{{ organization }}: {{ student }}-এর {{ exam }}-এর ফলাফল প্রকাশিত হয়েছে। ফলাফল জানতে অনুগ্রহ করে অফিসে যোগাযোগ করুন।',
            'sms' => '{{ organization }}: {{ student }}-এর {{ exam }}-এর ফলাফল প্রকাশিত হয়েছে। অনুগ্রহ করে অফিসে যোগাযোগ করুন।',
            'action' => 'পোর্টাল খুলুন',
        ],
    ],
    'catalog' => [
        'education_exams_result_published' => [
            'name' => 'ফলাফল প্রকাশ',
            'description' => 'পরীক্ষা বা চূড়ান্ত ফল প্রকাশ হলে পরিবারকে জিপিএসহ (নিয়ম অনুযায়ী)।',
        ],
        'education_exams_result_held' => [
            'name' => 'ফলাফল প্রকাশ, আটকানো',
            'description' => 'বকেয়া ফির কারণে যে শিক্ষার্থীর ফল আটকানো, তার পরিবারকে (নিয়ম অনুযায়ী): গ্রেড ছাড়া, অফিসে যোগাযোগ।',
        ],
    ],
];
