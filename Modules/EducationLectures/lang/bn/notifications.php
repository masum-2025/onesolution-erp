<?php

// ক্লাস লেকচার যে বার্তা পাঠায়।
return [
    'templates' => [
        'education_lectures_published' => [
            'subject' => '{{ subject }}-এ নতুন লেকচার: {{ title }}',
            'body' => '{{ organization }}-এ {{ subject }}-এর জন্য {{ teacher }} "{{ title }}" দিয়েছেন।',
            'sms' => '{{ organization }}: {{ subject }}-এ নতুন লেকচার।',
            'action' => 'লেকচার খুলুন',
        ],
    ],
    'catalog' => [
        'education_lectures_published' => [
            'name' => 'নতুন লেকচার',
            'description' => 'শাখার লেকচার প্রকাশ হলে লগইন আছে এমন শিক্ষার্থী আর অভিভাবককে।',
        ],
    ],
];
