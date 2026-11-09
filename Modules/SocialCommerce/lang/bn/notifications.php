<?php

return [
    'templates' => [
        'social_commerce_post_waiting' => [
            'subject' => '{{ page }}-এর একটি Facebook পোস্ট আপনার অনুমোদনের অপেক্ষায়',
            'body' => '{{ author }} {{ organization }}-এর Facebook পেজ {{ page }}-এর জন্য একটি পোস্ট লিখেছেন। পাঠাতে অনুমোদন দিন, অথবা কারণসহ বাতিল করুন।',
            'action' => 'অপেক্ষায় থাকা পোস্ট দেখুন',
        ],
        'social_commerce_post_failed' => [
            'subject' => '{{ page }}-এর জন্য আপনার Facebook পোস্টটি যায়নি',
            'body' => '{{ page }} ({{ organization }})-এর জন্য আপনার পোস্টটি Facebook নেয়নি। {{ reason }}',
            'action' => 'পোস্টটি খুলুন',
        ],
        'social_commerce_page_needs_reconnect' => [
            'subject' => 'Facebook পেজ {{ page }} আবার connect করুন',
            'body' => '{{ organization }}-এর পেজ {{ page }}-এর sign-in Facebook আর মানছে না। পেজটি পরিচালনা করেন এমন কেউ আবার connect না করা পর্যন্ত পোস্ট যাবে না।',
            'action' => 'পেজগুলো খুলুন',
        ],
    ],
    'catalog' => [
        'social_commerce_post_waiting' => [
            'name' => 'অনুমোদনের অপেক্ষায় Facebook পোস্ট',
            'description' => 'কোনো পোস্ট অনুমোদনে পাঠানো হলে, যাঁরা পোস্ট অনুমোদন করেন তাঁদের কাছে।',
        ],
        'social_commerce_post_failed' => [
            'name' => 'Facebook পোস্ট যায়নি',
            'description' => 'Facebook যে পোস্ট নেয়নি তার লেখকের কাছে।',
        ],
        'social_commerce_page_needs_reconnect' => [
            'name' => 'Facebook পেজে আবার sign-in দরকার',
            'description' => 'Facebook কোনো পেজের sign-in আর না মানলে, যাঁরা পেজ connect করেন তাঁদের কাছে।',
        ],
    ],
];
