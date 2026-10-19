<?php

// Messages Leave sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'leave_requested' => [
            'subject' => '{{ employee }} {{ type }} চেয়েছেন',
            'body' => '{{ organization }}-এর {{ employee }} {{ type }} চেয়েছেন: {{ dates }} ({{ days }} দিন)।

অনুমোদন বা নামঞ্জুর করতে আবেদনটি খুলুন।',
            'action' => 'ছুটির আবেদন খুলুন',
        ],
        'leave_decided' => [
            'subject' => 'আপনার {{ type }}: {{ decision }}',
            'body' => '{{ organization }}-এ আপনার {{ type }}-এর আবেদন, {{ dates }} ({{ days }} দিন), {{ decision }}।

{{ note }}',
            'action' => 'আমার ছুটি খুলুন',
        ],
    ],
    'catalog' => [
        'leave_requested' => ['name' => 'ছুটির আবেদন', 'description' => 'কোনো কর্মী ছুটি চাইলে যাঁরা অনুমোদন করতে পারেন তাঁদের কাছে।'],
        'leave_decided' => ['name' => 'ছুটির সিদ্ধান্ত', 'description' => 'ছুটি অনুমোদিত বা নামঞ্জুর হলে কর্মীর কাছে।'],
    ],
];
