<?php

return [
    'templates' => [
        'business_network_connected' => [
            'subject' => '{{ other }} আপনার সংযোগ গ্রহণ করেছে',
            'body' => '{{ other }} {{ organization }}-এর সাথে ব্যবসায়িক সংযোগ গ্রহণ করেছে। তারা যেসব কারখানা দেখিয়েছে, সেগুলো নিয়ে এখন একসাথে কাজ করা যাবে।',
            'action' => 'সংযোগ খুলুন',
        ],
        'business_network_paused' => [
            'subject' => '{{ other }} আপনার সংযোগে বিরতি দিয়েছে',
            'body' => '{{ other }} {{ organization }}-এর সাথে ব্যবসায়িক সংযোগে বিরতি দিয়েছে। তারা আবার চালু না করা পর্যন্ত কোনো দিকে কিছু পাঠানো যাবে না।',
            'action' => 'সংযোগ খুলুন',
        ],
        'business_network_ended' => [
            'subject' => '{{ other }} আপনার সংযোগ শেষ করেছে',
            'body' => '{{ other }} {{ organization }}-এর সাথে ব্যবসায়িক সংযোগ শেষ করেছে। যা পেয়েছেন তা আপনারই থাকবে; আর কিছু পাঠানো যাবে না।

{{ reason }}',
            'action' => 'সংযোগ খুলুন',
        ],
        'business_network_message_failed' => [
            'subject' => '{{ other }}-কে পাঠানো একটা জিনিস পৌঁছায়নি',
            'body' => '{{ organization }} থেকে {{ other }}-কে পাঠানো একটা জিনিস পৌঁছানো যায়নি: {{ reason }}

সংযোগ খুলে দেখুন, দরকার হলে আবার পাঠান।',
            'action' => 'সংযোগ খুলুন',
        ],
    ],
    'catalog' => [
        'business_network_connected' => [
            'name' => 'সংযোগ গৃহীত',
            'description' => 'ক্লায়েন্ট আমন্ত্রণ গ্রহণ করলে, যাঁরা সংযোগ পরিচালনা করেন তাঁদের কাছে।',
        ],
        'business_network_paused' => [
            'name' => 'সংযোগে বিরতি',
            'description' => 'অন্য পক্ষ বিরতি দিলে, যাঁরা সংযোগ পরিচালনা করেন তাঁদের কাছে।',
        ],
        'business_network_ended' => [
            'name' => 'সংযোগ শেষ',
            'description' => 'অন্য পক্ষ সংযোগ শেষ করলে, যাঁরা সংযোগ পরিচালনা করেন তাঁদের কাছে।',
        ],
        'business_network_message_failed' => [
            'name' => 'পৌঁছায়নি',
            'description' => 'অন্য পক্ষকে পাঠানো কিছু পৌঁছানো না গেলে, যাঁরা সংযোগ পরিচালনা করেন তাঁদের কাছে।',
        ],
    ],
];
