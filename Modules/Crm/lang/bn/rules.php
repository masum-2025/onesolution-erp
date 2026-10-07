<?php

// CRM rule labels.
return [
    'duplicate_match' => [
        'label' => 'একই যোগাযোগ ধরা হবে যখন',
        'description' => 'phone: মোবাইল নম্বর মিলে; phone_email: নম্বর বা ইমেইল মিলে।',
    ],
    'marketing_consent_required' => [
        'label' => 'মার্কেটিংয়ে সম্মতি লাগবে',
        'description' => 'অফার SMS বা ইমেইলে শুধু যাঁরা রাজি হয়েছেন তাঁদের (সময়সহ রাখা হয়)।',
    ],
    'follow_up_reminder_minutes' => [
        'label' => 'ফলো-আপের কত আগে মনে করানো (মিনিট)',
        'description' => 'যাঁকে ফলো-আপ দেওয়া, তাঁকে এতক্ষণ আগে মনে করানো হবে।',
    ],
    'loyalty_points_per_100' => [
        'label' => 'প্রতি ১০০ টাকায় লয়্যালটি পয়েন্ট',
        'description' => 'কাউন্টারে প্রতি ১০০ টাকার কেনায় গ্রাহক এত পয়েন্ট পাবেন। ০ = পয়েন্ট নেই।',
    ],
    'quote_valid_days' => [
        'label' => 'কোটেশনের মেয়াদ (দিন)',
        'description' => 'দেওয়ার দিন থেকে, কোটেশনে আলাদা না লেখা থাকলে।',
    ],
    'quote_prices_include_tax' => [
        'label' => 'কোটেশনের দামে ভ্যাট ধরা',
        'description' => 'এস্টিমেট ও কোটেশনে লেখা দামে ভ্যাট আছে; নইলে যোগ হবে।',
    ],
    'number_prefixes' => [
        'label' => 'এস্টিমেট ও কোটেশন নম্বরের শুরু',
        'description' => 'বছর ও নম্বরের আগে, যেমন QT-2026-00001।',
    ],
];
