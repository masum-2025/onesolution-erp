<?php

return [
    'fiscal_year_start' => [
        'label' => 'অর্থবছরের শুরু (মাস-দিন)',
        'description' => 'আর্থিক বছরের প্রথম দিন।',
    ],
    'journal_approval_above' => [
        'label' => 'যে অঙ্কের বেশি জাবেদায় অনুমোদন লাগবে',
        'description' => 'এই অঙ্কের বেশি জাবেদা এন্ট্রিতে দ্বিতীয় অনুমোদন লাগবে। খালি = কখনো নয়।',
    ],
    'allow_backdated_entries_days' => [
        'label' => 'পূর্বের তারিখে এন্ট্রি (দিন)',
        'description' => 'কত দিন আগের তারিখে এন্ট্রি দেওয়া যাবে।',
    ],

    'categories' => [
        'approval' => 'অনুমোদন',
        'period' => 'হিসাবকাল',
    ],
];
