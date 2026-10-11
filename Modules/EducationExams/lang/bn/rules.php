<?php

// Rule labels (manifest "rules"), key = part after "education_exams.".
return [
    'default_grading_scheme' => [
        'label' => 'নতুন পরীক্ষার গ্রেডিং',
        'description' => 'প্রতিষ্ঠানের একটি গ্রেডিং পদ্ধতির কোড (যেমন bd_school_gpa5, bd_university_ugc, percentage)। প্রতিটি পরীক্ষা অন্যটি বেছে নিতে পারে।',
    ],
    'moderation_required' => [
        'label' => 'নম্বরে অনুমোদন লাগবে',
        'description' => 'চালু: শিক্ষকের জমা দেওয়া নম্বর অন্য কেউ অনুমোদন করবেন বা ফেরত পাঠাবেন। বন্ধ: জমা দিলেই অনুমোদিত।',
    ],
    'categories' => [
        'grading' => 'গ্রেডিং',
        'marks' => 'নম্বর',
    ],
];
