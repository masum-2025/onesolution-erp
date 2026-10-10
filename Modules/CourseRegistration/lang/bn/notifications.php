<?php

// কোর্স রেজিস্ট্রেশন যে বার্তা পাঠায়।
return [
    'templates' => [
        'course_registration_seat_offered' => [
            'subject' => '{{ subject }}-এ আপনি আসন পেয়েছেন',
            'body' => '{{ organization }}-এ {{ subject }} ({{ session }})-এর একটি আসন খালি হয়েছে, এখন সেটি আপনার। আপনার রেজিস্ট্রেশন দেখুন।',
            'sms' => '{{ organization }}: {{ subject }} ({{ session }})-এ আপনি আসন পেয়েছেন।',
            'action' => 'আমার রেজিস্ট্রেশন খুলুন',
        ],
    ],
    'catalog' => [
        'course_registration_seat_offered' => ['name' => 'অপেক্ষার তালিকা থেকে আসন', 'description' => 'অপেক্ষার তালিকা থেকে খালি আসন পাওয়া শিক্ষার্থীকে।'],
    ],
];
