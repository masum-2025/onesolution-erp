<?php

/*
| Preset: an Alia madrasa (Bangladesh): Ebtedayee, Dakhil and Alim, year by year.
*/

$levels = fn (string $prefix, array $names) => array_map(fn (array $name, int $index) => [
    'code' => $prefix.($index + 1),
    'name' => $name,
], $names, array_keys($names));

return [
    'key' => 'madrasa',
    'name' => ['en' => 'Madrasa (Alia)', 'bn' => 'মাদ্রাসা (আলিয়া)'],
    'description' => ['en' => 'Ebtedayee (1-5), Dakhil (6-10) and Alim (11-12), year by year.', 'bn' => 'ইবতেদায়ি (১-৫), দাখিল (৬-১০) ও আলিম (১১-১২), বছর অনুযায়ী।'],
    'sectors' => ['madrasa'],
    'lists' => require __DIR__.'/_common_lists.php',
    'programs' => [
        [
            'code' => 'EBT',
            'name' => ['en' => 'Ebtedayee', 'bn' => 'ইবতেদায়ি'],
            'progression' => 'year',
            'level_label' => ['en' => 'Class', 'bn' => 'শ্রেণি'],
            'section_label' => ['en' => 'Section', 'bn' => 'শাখা'],
            'levels' => $levels('E', array_map(fn (int $n) => ['en' => "Class {$n}", 'bn' => 'শ্রেণি '.strtr((string) $n, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯'])], range(1, 5))),
        ],
        [
            'code' => 'DKL',
            'name' => ['en' => 'Dakhil', 'bn' => 'দাখিল'],
            'progression' => 'year',
            'level_label' => ['en' => 'Class', 'bn' => 'শ্রেণি'],
            'section_label' => ['en' => 'Section', 'bn' => 'শাখা'],
            'levels' => $levels('D', array_map(fn (int $n) => ['en' => "Class {$n}", 'bn' => 'শ্রেণি '.strtr((string) $n, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯'])], range(6, 10))),
        ],
        [
            'code' => 'ALM',
            'name' => ['en' => 'Alim', 'bn' => 'আলিম'],
            'progression' => 'year',
            'level_label' => ['en' => 'Year', 'bn' => 'বর্ষ'],
            'section_label' => ['en' => 'Group', 'bn' => 'গ্রুপ'],
            'levels' => [
                ['code' => 'A1', 'name' => ['en' => 'Alim 1st year', 'bn' => 'আলিম প্রথম বর্ষ']],
                ['code' => 'A2', 'name' => ['en' => 'Alim 2nd year', 'bn' => 'আলিম দ্বিতীয় বর্ষ']],
            ],
        ],
    ],
    'subjects' => [
        ['code' => 'QUR', 'name' => ['en' => 'Quran Majid and Tajweed', 'bn' => 'কুরআন মাজিদ ও তাজবিদ']],
        ['code' => 'HAD', 'name' => ['en' => 'Hadith', 'bn' => 'হাদিস শরিফ']],
        ['code' => 'ARB', 'name' => ['en' => 'Arabic', 'bn' => 'আরবি']],
        ['code' => 'FIQ', 'name' => ['en' => 'Aqaid and Fiqh', 'bn' => 'আকাইদ ও ফিকহ']],
        ['code' => 'BAN', 'name' => ['en' => 'Bangla', 'bn' => 'বাংলা']],
        ['code' => 'ENG', 'name' => ['en' => 'English', 'bn' => 'ইংরেজি']],
        ['code' => 'MATH', 'name' => ['en' => 'Mathematics', 'bn' => 'গণিত']],
        ['code' => 'SCI', 'name' => ['en' => 'Science', 'bn' => 'বিজ্ঞান']],
    ],
    'fields' => require __DIR__.'/_bd_fields.php',
];
