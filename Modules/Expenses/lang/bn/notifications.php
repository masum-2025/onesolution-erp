<?php

return [
    'templates' => [
        'expenses_submitted' => [
            'subject' => '{{ employee }}-এর খরচের দাবি {{ number }} অনুমোদনের অপেক্ষায়',
            'body' => '{{ organization }}-এর {{ employee }} খরচের দাবি {{ number }} ({{ amount }}) পাঠিয়েছেন।

রসিদ দেখে সিদ্ধান্ত দিতে খুলুন।',
            'action' => 'অনুমোদনের তালিকা খুলুন',
        ],
        'expenses_decided' => [
            'subject' => 'আপনার খরচের দাবি {{ number }}: {{ decision }}',
            'body' => '{{ organization }}-এ আপনার খরচের দাবি {{ number }} ({{ amount }}) {{ decision }}।

{{ note }}',
            'action' => 'আমার খরচ খুলুন',
        ],
        'expenses_paid' => [
            'subject' => 'আপনার খরচের দাবি {{ number }} পরিশোধিত',
            'body' => '{{ organization }}-এ আপনার খরচের দাবি {{ number }} ({{ amount }})-এর টাকা ফেরত দেওয়া হয়েছে।',
            'action' => 'আমার খরচ খুলুন',
        ],
        'expenses_advance_submitted' => [
            'subject' => '{{ employee }}-এর খরচের অগ্রিম {{ number }} অনুমোদনের অপেক্ষায়',
            'body' => '{{ organization }}-এ {{ employee }} খরচের অগ্রিম {{ number }} ({{ amount }}) চেয়েছেন।

সিদ্ধান্ত দিতে খুলুন।',
            'action' => 'অগ্রিমগুলো খুলুন',
        ],
        'expenses_advance_decided' => [
            'subject' => 'আপনার খরচের অগ্রিম {{ number }}: {{ decision }}',
            'body' => '{{ organization }}-এ আপনার খরচের অগ্রিম {{ number }} ({{ amount }}): {{ decision }}।

{{ note }}',
            'action' => 'আমার খরচ খুলুন',
        ],
    ],
    'catalog' => [
        'expenses_submitted' => [
            'name' => 'অনুমোদনের অপেক্ষায় খরচের দাবি',
            'description' => 'যাঁরা দাবির পরের ধাপের সিদ্ধান্ত দিতে পারেন তাঁদের।',
        ],
        'expenses_decided' => [
            'name' => 'খরচের দাবির সিদ্ধান্ত',
            'description' => 'কর্মীকে, তাঁর দাবি অনুমোদিত বা বাতিল হলে।',
        ],
        'expenses_paid' => [
            'name' => 'খরচের দাবি পরিশোধিত',
            'description' => 'কর্মীকে, তাঁর দাবির টাকা ফেরত দেওয়া হলে।',
        ],
        'expenses_advance_submitted' => [
            'name' => 'অনুমোদনের অপেক্ষায় খরচের অগ্রিম',
            'description' => 'অগ্রিমের পরের ধাপে যাঁরা সিদ্ধান্ত দিতে পারেন তাঁদের কাছে।',
        ],
        'expenses_advance_decided' => [
            'name' => 'খরচের অগ্রিমের সিদ্ধান্ত বা দেওয়া',
            'description' => 'অগ্রিম অনুমোদিত, বাতিল বা দেওয়া হলে কর্মীর কাছে।',
        ],
    ],
];
