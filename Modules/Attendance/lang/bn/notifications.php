<?php

// Messages Attendance sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'attendance_absent_streak' => [
            'subject' => '{{ organization }}-এ {{ count }} জন কর্মী টানা অনেক দিন অনুপস্থিত',
            'body' => '{{ organization }}-এর এই কর্মীরা ছুটি ছাড়া টানা অনেক কর্মদিবস অনুপস্থিত:

{{ people }}

তাঁদের সাথে যোগাযোগ করুন। অনুপস্থিতি চলতে থাকলে কোনো ব্যবস্থা নেওয়ার আগে আপনার নীতি ও আইন (যেমন লিখিত নোটিশ) মেনে চলুন।',
            'action' => 'হাজিরা খুলুন',
        ],
    ],
    'catalog' => [
        'attendance_absent_streak' => ['name' => 'টানা অনেক দিন অনুপস্থিত', 'description' => 'কেউ নির্ধারিত সংখ্যক কর্মদিবস টানা অনুপস্থিত থাকলে কর্মী দেখাশোনা করা ব্যক্তিদের কাছে।'],
    ],
    'since' => ':date থেকে :days কর্মদিবস',
];
