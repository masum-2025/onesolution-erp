<?php

// Messages CRM sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'crm_follow_up_due' => [
            'subject' => '{{ organization }}-এ {{ count }}টি ফলো-আপের সময় হয়েছে',
            'body' => '{{ organization }}-এ আপনার আসন্ন ফলো-আপ:

{{ tasks }}

ফোন, দেখা বা লেখার জন্য ফলো-আপ খুলুন।',
            'action' => 'ফলো-আপ খুলুন',
        ],
    ],
    'catalog' => [
        'crm_follow_up_due' => [
            'name' => 'ফলো-আপের সময়',
            'description' => 'যাঁকে CRM ফলো-আপ দেওয়া, তাঁকে সময়ের একটু আগে।',
        ],
    ],
];
