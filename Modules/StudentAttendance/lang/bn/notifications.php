<?php

// হাজিরা যে বার্তা পাঠায় (NotificationCatalog: মডিউলের বার্তা)।
return [
    'templates' => [
        'student_attendance_absent' => [
            'subject' => '{{ student }} আজ অনুপস্থিত',
            'body' => '{{ organization }}: আজ ({{ date }}) {{ student }}-কে অনুপস্থিত দেখানো হয়েছে। ভুল হলে বা কারণ জানা থাকলে অনুগ্রহ করে প্রতিষ্ঠানকে জানান।',
            'sms' => '{{ organization }}: {{ student }} আজ ({{ date }}) অনুপস্থিত। অনুগ্রহ করে কারণ জানান।',
            'action' => 'হাজিরা দেখুন',
        ],
        'student_attendance_skipped' => [
            'subject' => '{{ student }} আজ একটি ক্লাসে ছিল না',
            'body' => '{{ organization }}: আজ ({{ date }}) {{ student }} প্রতিষ্ঠানে এসেছিল, কিন্তু {{ period }} {{ subject }} ক্লাসে অনুপস্থিত ছিল।',
            'sms' => '{{ organization }}: {{ student }} আজ আসার পরে {{ period }} {{ subject }} ক্লাসে অনুপস্থিত ছিল।',
            'action' => 'হাজিরা দেখুন',
        ],
    ],
    'catalog' => [
        'student_attendance_absent' => [
            'name' => 'শিক্ষার্থী আজ অনুপস্থিত',
            'description' => 'সন্তানকে আজ অনুপস্থিত দেখালে পরিবারকে, দিনে একবার (নিয়ম)।',
        ],
        'student_attendance_skipped' => [
            'name' => 'শিক্ষার্থী একটি ক্লাসে ছিল না',
            'description' => 'সন্তান এসে পরে কোনো পিরিয়ডে না থাকলে পরিবারকে (পিরিয়ড-ভিত্তিক হাজিরা, নিয়ম)।',
        ],
    ],
];
