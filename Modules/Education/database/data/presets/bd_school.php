<?php

/*
| Preset: a Bangladeshi school (play group to class 10), year by year,
| sections ("শাখা"), shifts, Bangla and English versions, and the groups of
| classes 9-10. Religion and blood group are the institution's own fields
| (data, not code). A starting point: everything is editable after applying.
*/

$classes = fn (int $from, int $to) => array_map(fn (int $class) => [
    'code' => "C{$class}",
    'name' => ['en' => "Class {$class}", 'bn' => 'শ্রেণি '.strtr((string) $class, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯'])],
    'min_age' => $class + 5,
], range($from, $to));

return [
    'key' => 'bd_school',
    'name' => ['en' => 'School (Bangladesh)', 'bn' => 'স্কুল (বাংলাদেশ)'],
    'description' => ['en' => 'Primary and secondary, class by class, with sections, shifts, versions and science/business/humanities groups.', 'bn' => 'প্রাথমিক ও মাধ্যমিক, শ্রেণি অনুযায়ী; শাখা, শিফট, ভার্সন এবং বিজ্ঞান/ব্যবসায়/মানবিক বিভাগসহ।'],
    'sectors' => ['school'],
    'lists' => require __DIR__.'/_common_lists.php',
    'programs' => [
        [
            'code' => 'PRE',
            'name' => ['en' => 'Pre-primary', 'bn' => 'প্রাক-প্রাথমিক'],
            'progression' => 'year',
            'level_label' => ['en' => 'Class', 'bn' => 'শ্রেণি'],
            'section_label' => ['en' => 'Section', 'bn' => 'শাখা'],
            'levels' => [
                ['code' => 'PG', 'name' => ['en' => 'Play group', 'bn' => 'প্লে গ্রুপ'], 'min_age' => 3],
                ['code' => 'NUR', 'name' => ['en' => 'Nursery', 'bn' => 'নার্সারি'], 'min_age' => 4],
                ['code' => 'KG', 'name' => ['en' => 'KG', 'bn' => 'কেজি'], 'min_age' => 5],
            ],
        ],
        [
            'code' => 'PRI',
            'name' => ['en' => 'Primary', 'bn' => 'প্রাথমিক'],
            'progression' => 'year',
            'level_label' => ['en' => 'Class', 'bn' => 'শ্রেণি'],
            'section_label' => ['en' => 'Section', 'bn' => 'শাখা'],
            'levels' => $classes(1, 5),
        ],
        [
            'code' => 'SEC',
            'name' => ['en' => 'Secondary', 'bn' => 'মাধ্যমিক'],
            'progression' => 'year',
            'level_label' => ['en' => 'Class', 'bn' => 'শ্রেণি'],
            'section_label' => ['en' => 'Section', 'bn' => 'শাখা'],
            'levels' => $classes(6, 10),
        ],
    ],
    'subjects' => [
        ['code' => 'BAN1', 'name' => ['en' => 'Bangla 1st paper', 'bn' => 'বাংলা প্রথম পত্র']],
        ['code' => 'BAN2', 'name' => ['en' => 'Bangla 2nd paper', 'bn' => 'বাংলা দ্বিতীয় পত্র']],
        ['code' => 'ENG1', 'name' => ['en' => 'English 1st paper', 'bn' => 'ইংরেজি প্রথম পত্র']],
        ['code' => 'ENG2', 'name' => ['en' => 'English 2nd paper', 'bn' => 'ইংরেজি দ্বিতীয় পত্র']],
        ['code' => 'MATH', 'name' => ['en' => 'Mathematics', 'bn' => 'গণিত']],
        ['code' => 'SCI', 'name' => ['en' => 'Science', 'bn' => 'বিজ্ঞান']],
        ['code' => 'BGS', 'name' => ['en' => 'Bangladesh and Global Studies', 'bn' => 'বাংলাদেশ ও বিশ্বপরিচয়']],
        ['code' => 'REL', 'name' => ['en' => 'Religion and moral education', 'bn' => 'ধর্ম ও নৈতিক শিক্ষা']],
        ['code' => 'ICT', 'name' => ['en' => 'Information and communication technology', 'bn' => 'তথ্য ও যোগাযোগ প্রযুক্তি'], 'kind' => 'practical'],
        ['code' => 'PHY', 'name' => ['en' => 'Physics', 'bn' => 'পদার্থবিজ্ঞান']],
        ['code' => 'CHEM', 'name' => ['en' => 'Chemistry', 'bn' => 'রসায়ন']],
        ['code' => 'BIO', 'name' => ['en' => 'Biology', 'bn' => 'জীববিজ্ঞান']],
        ['code' => 'HMATH', 'name' => ['en' => 'Higher mathematics', 'bn' => 'উচ্চতর গণিত']],
        ['code' => 'ACC', 'name' => ['en' => 'Accounting', 'bn' => 'হিসাববিজ্ঞান']],
        ['code' => 'AGRI', 'name' => ['en' => 'Agriculture studies', 'bn' => 'কৃষিশিক্ষা']],
    ],
    'fields' => require __DIR__.'/_bd_fields.php',
];
