<?php

// বহির্বিভাগ যে বার্তা পাঠায় (NotificationCatalog: module notifications).
return [
    'templates' => [
        'outpatient_serial_booked' => [
            'subject' => '{{ doctor }}-এর কাছে আপনার সিরিয়াল {{ number }}',
            'body' => '{{ organization }}: {{ date }} তারিখ {{ time }} থেকে {{ doctor }}-এর কাছে আপনার সিরিয়াল {{ number }}, {{ chamber }}।',
            'sms' => '{{ organization }}: সিরিয়াল {{ number }}, {{ doctor }}, {{ date }} {{ time }}, {{ chamber }}।',
            'action' => 'বিস্তারিত দেখুন',
        ],
    ],
    'catalog' => [
        'outpatient_serial_booked' => [
            'name' => 'ডাক্তারের সিরিয়াল',
            'description' => 'ডেস্ক সিরিয়াল দিলে রোগীকে যায় (নিয়ম "রোগীকে সিরিয়াল SMS করুন")।',
        ],
    ],
];
