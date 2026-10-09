<?php

/*
| Preset: a university taught by semester (two a year here; change
| periods_per_year to 3 for trimesters), with faculties, departments,
| credit-based programs and a few first-year courses.
*/

$semesters = fn (int $count) => array_map(fn (int $number) => [
    'code' => "S{$number}",
    'name' => ['en' => "Semester {$number}", 'bn' => 'সেমিস্টার '.strtr((string) $number, ['1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮'])],
], range(1, $count));

return [
    'key' => 'university',
    'name' => ['en' => 'University (semesters)', 'bn' => 'বিশ্ববিদ্যালয় (সেমিস্টার)'],
    'description' => ['en' => 'Faculties and departments, credit-based programs by semester, and courses with credits.', 'bn' => 'অনুষদ ও বিভাগ, সেমিস্টার অনুযায়ী ক্রেডিটভিত্তিক প্রোগ্রাম এবং ক্রেডিটসহ কোর্স।'],
    'sectors' => ['university'],
    'lists' => [
        'gender' => (require __DIR__.'/_common_lists.php')['gender'],
        'relation' => (require __DIR__.'/_common_lists.php')['relation'],
        'category' => [
            ['key' => 'regular', 'name' => ['en' => 'Regular', 'bn' => 'নিয়মিত']],
            ['key' => 'waiver', 'name' => ['en' => 'Tuition waiver', 'bn' => 'টিউশন মওকুফ']],
            ['key' => 'international', 'name' => ['en' => 'International', 'bn' => 'আন্তর্জাতিক']],
        ],
    ],
    'units' => [
        ['code' => 'FSE', 'kind' => 'faculty', 'name' => ['en' => 'Faculty of Science and Engineering', 'bn' => 'বিজ্ঞান ও প্রকৌশল অনুষদ']],
        ['code' => 'FBS', 'kind' => 'faculty', 'name' => ['en' => 'Faculty of Business Studies', 'bn' => 'ব্যবসায় শিক্ষা অনুষদ']],
        ['code' => 'CSE', 'kind' => 'department', 'parent' => 'FSE', 'name' => ['en' => 'Department of Computer Science and Engineering', 'bn' => 'কম্পিউটার বিজ্ঞান ও প্রকৌশল বিভাগ']],
        ['code' => 'BUS', 'kind' => 'department', 'parent' => 'FBS', 'name' => ['en' => 'Department of Business Administration', 'bn' => 'ব্যবসায় প্রশাসন বিভাগ']],
    ],
    'programs' => [
        [
            'code' => 'BSCSE',
            'unit' => 'CSE',
            'name' => ['en' => 'BSc in Computer Science and Engineering', 'bn' => 'কম্পিউটার বিজ্ঞান ও প্রকৌশলে বিএসসি'],
            'progression' => 'semester',
            'periods_per_year' => 2,
            'total_credits_centi' => 16000,
            'level_label' => ['en' => 'Semester', 'bn' => 'সেমিস্টার'],
            'section_label' => ['en' => 'Section', 'bn' => 'সেকশন'],
            'levels' => $semesters(8),
        ],
        [
            'code' => 'BBA',
            'unit' => 'BUS',
            'name' => ['en' => 'Bachelor of Business Administration', 'bn' => 'ব্যাচেলর অব বিজনেস অ্যাডমিনিস্ট্রেশন'],
            'progression' => 'semester',
            'periods_per_year' => 2,
            'total_credits_centi' => 13200,
            'level_label' => ['en' => 'Semester', 'bn' => 'সেমিস্টার'],
            'section_label' => ['en' => 'Section', 'bn' => 'সেকশন'],
            'levels' => $semesters(8),
        ],
    ],
    'subjects' => [
        ['code' => 'CSE101', 'name' => ['en' => 'Structured programming', 'bn' => 'স্ট্রাকচার্ড প্রোগ্রামিং'], 'credits_centi' => 300],
        ['code' => 'CSE102', 'name' => ['en' => 'Structured programming lab', 'bn' => 'স্ট্রাকচার্ড প্রোগ্রামিং ল্যাব'], 'credits_centi' => 150, 'kind' => 'lab'],
        ['code' => 'CSE201', 'name' => ['en' => 'Data structures', 'bn' => 'ডেটা স্ট্রাকচার'], 'credits_centi' => 300],
        ['code' => 'MAT101', 'name' => ['en' => 'Calculus', 'bn' => 'ক্যালকুলাস'], 'credits_centi' => 300],
        ['code' => 'ENG101', 'name' => ['en' => 'English composition', 'bn' => 'ইংরেজি রচনা'], 'credits_centi' => 300],
        ['code' => 'BUS101', 'name' => ['en' => 'Principles of management', 'bn' => 'ব্যবস্থাপনার নীতিমালা'], 'credits_centi' => 300],
        ['code' => 'ACT101', 'name' => ['en' => 'Financial accounting', 'bn' => 'আর্থিক হিসাববিজ্ঞান'], 'credits_centi' => 300],
    ],
    'fields' => [
        [
            'entity' => 'admission', 'key' => 'hsc_gpa', 'type' => 'number',
            'label' => ['en' => 'HSC GPA', 'bn' => 'এইচএসসি জিপিএ'],
        ],
        [
            'entity' => 'student', 'key' => 'blood_group', 'type' => 'choice', 'portal_visible' => true, 'on_documents' => true,
            'label' => ['en' => 'Blood group', 'bn' => 'রক্তের গ্রুপ'],
            'options' => array_map(fn (string $group) => ['value' => $group, 'label' => ['en' => $group, 'bn' => $group]], ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']),
        ],
    ],
];
