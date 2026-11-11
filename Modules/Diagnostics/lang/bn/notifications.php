<?php

// ডায়াগনস্টিক্সের পাঠানো বার্তা (NotificationCatalog: module notifications)।
return [
    'templates' => [
        'diagnostics_report_link' => [
            'subject' => 'আপনার ল্যাব রিপোর্ট {{ number }} তৈরি',
            'body' => '{{ organization }}: আপনার ল্যাব রিপোর্ট {{ number }} তৈরি। পরের {{ hours }} ঘণ্টা এখানে খুলে দেখতে পারবেন।',
            'sms' => '{{ organization }}: ল্যাব রিপোর্ট {{ number }} তৈরি। দেখুন ({{ hours }} ঘণ্টা): {{ link }}',
            'action' => 'রিপোর্ট দেখুন',
        ],
    ],
    'catalog' => [
        'diagnostics_report_link' => [
            'name' => 'ল্যাব রিপোর্টের link',
            'description' => 'Desk থেকে রিপোর্টের link পাঠালে, বা সব test যাচাই হওয়ামাত্র (নিয়ম চালু থাকলে) রোগীকে যায়।',
        ],
    ],
];
