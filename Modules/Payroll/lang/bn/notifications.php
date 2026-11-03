<?php

// Payroll যেসব বার্তা পাঠায় (NotificationCatalog: module notifications)। কখনও টাকার অঙ্ক নয়।
return [
    'templates' => [
        'payroll_salary_revised' => [
            'subject' => '{{ organization }}-এ আপনার বেতন নতুন করা হয়েছে',
            'body' => '{{ organization }}-এ আপনার বেতন {{ date }} থেকে নতুন করা হয়েছে।

নতুন অঙ্ক আপনার বেতন স্লিপে দেখতে পাবেন।',
            'action' => 'আমার বেতন স্লিপ খুলুন',
        ],
    ],
    'catalog' => [
        'payroll_salary_revised' => ['name' => 'বেতন নতুন করা হয়েছে', 'description' => 'বেতন বৃদ্ধিতে কর্মীর নতুন বেতন বসলে তাঁকে জানানো হয় (বার্তায় টাকার অঙ্ক থাকে না)।'],
    ],
];
