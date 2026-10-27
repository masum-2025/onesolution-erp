<?php

return [
    'templates' => [
        'appraisals_opened' => [
            'subject' => '{{ cycle }}: আপনার মূল্যায়ন শুরু হয়েছে',
            'body' => '{{ organization }}-এ {{ cycle }} মূল্যায়ন শুরু হয়েছে।

{{ due }}-এর মধ্যে আপনার লক্ষ্যগুলো লিখুন।',
            'action' => 'আমার মূল্যায়ন খুলুন',
        ],
        'appraisals_your_turn' => [
            'subject' => '{{ cycle }}: {{ employee }} — {{ step }}',
            'body' => '{{ employee }}-এর মূল্যায়ন ({{ cycle }}, {{ organization }}) আপনার অপেক্ষায়: {{ step }}।

{{ due }}-এর মধ্যে করুন।',
            'action' => 'মূল্যায়ন খুলুন',
        ],
        'appraisals_reminder' => [
            'subject' => 'মনে করিয়ে দেওয়া — {{ cycle }}: {{ employee }}',
            'body' => '{{ employee }}-এর মূল্যায়ন ({{ cycle }}, {{ organization }}) এখনো আপনার অপেক্ষায়: {{ step }}। শেষ দিন {{ due }}।',
            'action' => 'মূল্যায়ন খুলুন',
        ],
    ],
    'catalog' => [
        'appraisals_opened' => [
            'name' => 'মূল্যায়ন শুরু',
            'description' => 'সদ্য খোলা মূল্যায়ন চক্রের প্রত্যেক কর্মীর কাছে।',
        ],
        'appraisals_your_turn' => [
            'name' => 'মূল্যায়ন আপনার অপেক্ষায়',
            'description' => 'মূল্যায়নে পরের কাজ যাঁর, তাঁর কাছে (mail-এ নম্বর বা মন্তব্য থাকে না)।',
        ],
        'appraisals_reminder' => [
            'name' => 'মূল্যায়ন মনে করানো',
            'description' => 'কোনো ধাপের শেষ দিনের আগে, যাঁর কাজ বাকি তাঁর কাছে।',
        ],
    ],
];
