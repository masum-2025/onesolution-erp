<?php

// ওয়েবসাইট যে বার্তা পাঠায় (NotificationCatalog: module notifications).
return [
    'templates' => [
        'edu_website_enquiry_received' => [
            'subject' => 'নতুন ভর্তির জিজ্ঞাসা: {{ level }}',
            'body' => '{{ organization }}-এর ওয়েবসাইটে কেউ ভর্তির ব্যাপারে জানতে চেয়েছেন ({{ level }})।

ফোন করে জানাতে জিজ্ঞাসাগুলো খুলুন।',
            'action' => 'জিজ্ঞাসা খুলুন',
        ],
    ],
    'catalog' => [
        'edu_website_enquiry_received' => ['name' => 'ভর্তির জিজ্ঞাসা এসেছে', 'description' => 'যাঁরা জিজ্ঞাসা দেখেন তাঁদের, ওয়েবসাইট থেকে জিজ্ঞাসা এলে (কোনো নাম বা নম্বর নয়)।'],
    ],
];
