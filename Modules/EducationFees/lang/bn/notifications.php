<?php

// ফির বার্তা (NotificationCatalog: module notifications).
return [
    'templates' => [
        'education_fees_due_soon' => [
            'subject' => '{{ student }}-এর ফি {{ due }} তারিখে দেয়',
            'body' => '{{ organization }}: {{ student }}-এর {{ number }} বিলের {{ amount }} {{ due }} তারিখের মধ্যে দিতে হবে। কাউন্টারে বা অনলাইনে দিতে পারেন।',
            'sms' => '{{ organization }}: {{ student }}-এর ফি {{ amount }} (বিল {{ number }}) {{ due }} তারিখের মধ্যে দিন।',
            'action' => 'ফি দেখুন',
        ],
        'education_fees_overdue' => [
            'subject' => '{{ student }}-এর ফি মেয়াদ পার',
            'body' => '{{ organization }}: {{ student }}-এর {{ number }} বিলের {{ amount }} {{ due }} তারিখে দেওয়ার কথা ছিল, এখনো দেওয়া হয়নি। দ্রুত দিন; বিলম্ব জরিমানা হতে পারে।',
            'sms' => '{{ organization }}: {{ student }}-এর ফি {{ amount }} (বিল {{ number }}) {{ due }} তারিখে দেয় ছিল। দ্রুত দিন।',
            'action' => 'ফি দিন',
        ],
        'education_fees_dues_reminder' => [
            'subject' => '{{ student }}-এর বকেয়া {{ amount }}',
            'body' => '{{ organization }}: {{ student }}-এর {{ bills }}টি বিলে বকেয়া {{ amount }}, সবচেয়ে পুরোনোটি {{ due }} তারিখে দেয় ছিল। কাউন্টারে বা অনলাইনে দিন।',
            'sms' => '{{ organization }}: {{ student }}-এর বকেয়া {{ amount }} ({{ bills }}টি বিল)। দ্রুত দিন।',
            'action' => 'ফি দেখুন',
        ],
        'education_fees_payment_received' => [
            'subject' => '{{ student }}-এর ফি জমা হয়েছে',
            'body' => '{{ organization }} {{ student }}-এর জন্য {{ amount }} পেয়েছে। রসিদ {{ number }}। ধন্যবাদ।',
            'sms' => '{{ organization }}: {{ student }}-এর {{ amount }} জমা হয়েছে, রসিদ {{ number }}। ধন্যবাদ।',
            'action' => 'রসিদ দেখুন',
        ],
    ],
    'catalog' => [
        'education_fees_due_soon' => [
            'name' => 'ফি দেওয়ার সময় হয়ে এসেছে',
            'description' => 'বিলের শেষ তারিখের কয়েক দিন আগে অভিভাবকদের (নিয়ম অনুযায়ী)।',
        ],
        'education_fees_overdue' => [
            'name' => 'ফি মেয়াদ পার',
            'description' => 'বাকি বিলের শেষ তারিখের কয়েক দিন পরে অভিভাবকদের (নিয়ম অনুযায়ী)।',
        ],
        'education_fees_dues_reminder' => [
            'name' => 'অফিসের ফি রিমাইন্ডার',
            'description' => 'অফিস যখন শিক্ষার্থীর বকেয়া মনে করিয়ে দেয়।',
        ],
        'education_fees_payment_received' => [
            'name' => 'ফি জমা',
            'description' => 'টাকা জমার পরে অভিভাবকদের, প্রতিষ্ঠান চাইলে।',
        ],
    ],
];
