<?php

/*
| Preset: a Bangladeshi higher secondary college (HSC, classes 11-12) by
| group, and a degree pass course by year.
*/

return [
    'key' => 'bd_college',
    'name' => ['en' => 'College (Bangladesh)', 'bn' => 'কলেজ (বাংলাদেশ)'],
    'description' => ['en' => 'Higher secondary (11-12) with science, business and humanities groups, and a three-year degree course.', 'bn' => 'উচ্চ মাধ্যমিক (একাদশ-দ্বাদশ) বিজ্ঞান, ব্যবসায় ও মানবিক বিভাগসহ, এবং তিন বছরের ডিগ্রি কোর্স।'],
    'sectors' => ['college'],
    'lists' => require __DIR__.'/_common_lists.php',
    'programs' => [
        [
            'code' => 'HSC',
            'name' => ['en' => 'Higher secondary', 'bn' => 'উচ্চ মাধ্যমিক'],
            'progression' => 'year',
            'level_label' => ['en' => 'Class', 'bn' => 'শ্রেণি'],
            'section_label' => ['en' => 'Group', 'bn' => 'গ্রুপ'],
            'levels' => [
                ['code' => 'XI', 'name' => ['en' => 'Class 11', 'bn' => 'একাদশ শ্রেণি'], 'min_age' => 16],
                ['code' => 'XII', 'name' => ['en' => 'Class 12', 'bn' => 'দ্বাদশ শ্রেণি'], 'min_age' => 17],
            ],
        ],
        [
            'code' => 'DEG',
            'name' => ['en' => 'Degree (pass)', 'bn' => 'ডিগ্রি (পাস)'],
            'progression' => 'year',
            'level_label' => ['en' => 'Year', 'bn' => 'বর্ষ'],
            'section_label' => ['en' => 'Group', 'bn' => 'গ্রুপ'],
            'levels' => [
                ['code' => 'Y1', 'name' => ['en' => '1st year', 'bn' => 'প্রথম বর্ষ']],
                ['code' => 'Y2', 'name' => ['en' => '2nd year', 'bn' => 'দ্বিতীয় বর্ষ']],
                ['code' => 'Y3', 'name' => ['en' => '3rd year', 'bn' => 'তৃতীয় বর্ষ']],
            ],
        ],
    ],
    'subjects' => [
        ['code' => 'BAN', 'name' => ['en' => 'Bangla', 'bn' => 'বাংলা']],
        ['code' => 'ENG', 'name' => ['en' => 'English', 'bn' => 'ইংরেজি']],
        ['code' => 'ICT', 'name' => ['en' => 'Information and communication technology', 'bn' => 'তথ্য ও যোগাযোগ প্রযুক্তি'], 'kind' => 'practical'],
        ['code' => 'PHY', 'name' => ['en' => 'Physics', 'bn' => 'পদার্থবিজ্ঞান']],
        ['code' => 'CHEM', 'name' => ['en' => 'Chemistry', 'bn' => 'রসায়ন']],
        ['code' => 'BIO', 'name' => ['en' => 'Biology', 'bn' => 'জীববিজ্ঞান']],
        ['code' => 'HMATH', 'name' => ['en' => 'Higher mathematics', 'bn' => 'উচ্চতর গণিত']],
        ['code' => 'ACC', 'name' => ['en' => 'Accounting', 'bn' => 'হিসাববিজ্ঞান']],
        ['code' => 'MGT', 'name' => ['en' => 'Business organization and management', 'bn' => 'ব্যবসায় সংগঠন ও ব্যবস্থাপনা']],
        ['code' => 'ECON', 'name' => ['en' => 'Economics', 'bn' => 'অর্থনীতি']],
        ['code' => 'CIV', 'name' => ['en' => 'Civics and good governance', 'bn' => 'পৌরনীতি ও সুশাসন']],
        ['code' => 'HIST', 'name' => ['en' => 'History', 'bn' => 'ইতিহাস']],
    ],
    'fields' => require __DIR__.'/_bd_fields.php',
];
