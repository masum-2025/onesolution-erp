<?php

/*
| Preset: a coaching centre: courses run in terms, students in batches
| ("ব্যাচ") by shift.
*/

return [
    'key' => 'coaching',
    'name' => ['en' => 'Coaching centre', 'bn' => 'কোচিং সেন্টার'],
    'description' => ['en' => 'Courses run in terms, with batches by shift.', 'bn' => 'টার্মে চলা কোর্স, শিফট অনুযায়ী ব্যাচ।'],
    'sectors' => ['coaching'],
    'lists' => [
        'gender' => (require __DIR__.'/_common_lists.php')['gender'],
        'relation' => (require __DIR__.'/_common_lists.php')['relation'],
        'shift' => [
            ['key' => 'morning', 'name' => ['en' => 'Morning', 'bn' => 'সকাল']],
            ['key' => 'afternoon', 'name' => ['en' => 'Afternoon', 'bn' => 'বিকেল']],
            ['key' => 'evening', 'name' => ['en' => 'Evening', 'bn' => 'সন্ধ্যা']],
        ],
    ],
    'programs' => [
        [
            'code' => 'SSCPREP',
            'name' => ['en' => 'SSC preparation', 'bn' => 'এসএসসি প্রস্তুতি'],
            'progression' => 'term',
            'periods_per_year' => 3,
            'level_label' => ['en' => 'Course', 'bn' => 'কোর্স'],
            'section_label' => ['en' => 'Batch', 'bn' => 'ব্যাচ'],
            'levels' => [
                ['code' => 'FULL', 'name' => ['en' => 'Full course', 'bn' => 'পূর্ণ কোর্স']],
            ],
        ],
        [
            'code' => 'ADMPREP',
            'name' => ['en' => 'University admission preparation', 'bn' => 'বিশ্ববিদ্যালয় ভর্তি প্রস্তুতি'],
            'progression' => 'term',
            'periods_per_year' => 2,
            'level_label' => ['en' => 'Course', 'bn' => 'কোর্স'],
            'section_label' => ['en' => 'Batch', 'bn' => 'ব্যাচ'],
            'levels' => [
                ['code' => 'FULL', 'name' => ['en' => 'Full course', 'bn' => 'পূর্ণ কোর্স']],
            ],
        ],
    ],
    'subjects' => [
        ['code' => 'MATH', 'name' => ['en' => 'Mathematics', 'bn' => 'গণিত']],
        ['code' => 'PHY', 'name' => ['en' => 'Physics', 'bn' => 'পদার্থবিজ্ঞান']],
        ['code' => 'CHEM', 'name' => ['en' => 'Chemistry', 'bn' => 'রসায়ন']],
        ['code' => 'ENG', 'name' => ['en' => 'English', 'bn' => 'ইংরেজি']],
    ],
];
