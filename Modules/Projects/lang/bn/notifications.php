<?php

return [
    'templates' => [
        'projects_task_assigned' => [
            'subject' => 'আপনার জন্য নতুন কাজ: {{ task }}',
            'body' => '{{ by }} আপনাকে {{ project }} ({{ organization }})-এ একটা কাজ দিয়েছেন: {{ task }}। শেষ দিন: {{ due }}।',
            'action' => 'আমার কাজ খুলুন',
        ],
        'projects_quote_answered' => [
            'subject' => '{{ other }} {{ project }}-এর কোটেশন {{ answer }}',
            'body' => '{{ other }} {{ project }} ({{ organization }})-এর কোটেশন {{ answer }}। সিআরএম-এ হালনাগাদ হয়েছে; গৃহীত হলে অ্যাকাউন্টিং-এ খসড়া ইনভয়েস তৈরি হয়েছে।',
            'action' => 'প্রজেক্ট খুলুন',
        ],
        'projects_task_overdue' => [
            'subject' => 'শেষ দিন পেরোনো কাজ: {{ task }}',
            'body' => '{{ project }} ({{ organization }})-এর "{{ task }}" কাজটার শেষ দিন ছিল {{ due }}, এখনো শেষ হয়নি।',
            'action' => 'আমার কাজ খুলুন',
        ],
    ],
    'catalog' => [
        'projects_task_assigned' => [
            'name' => 'আপনাকে দেওয়া কাজ',
            'description' => 'প্রজেক্টের কোনো কাজে যোগ করা প্রত্যেককে।',
        ],
        'projects_quote_answered' => [
            'name' => 'কোটেশনে ক্লায়েন্টের উত্তর',
            'description' => 'সংযুক্ত ক্লায়েন্টকে যিনি কোটেশন পাঠিয়েছেন তাঁকে, আর প্রজেক্টের ম্যানেজারকে।',
        ],
        'projects_task_overdue' => [
            'name' => 'শেষ দিন পেরোনো কাজ',
            'description' => 'কাজের শেষ দিন পেরোলে আর শেষ না হলে, তার লোকদের একবার।',
        ],
    ],
];
