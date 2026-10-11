<?php

/*
| Chart of accounts template "school": the general chart plus fee income and
| teaching costs. Names and codes are a starting point for an accountant.
*/

return [
    'key' => 'school',
    'extends' => 'general',
    'accounts' => [
        ['code' => '1145', 'name' => ['en' => 'Fees receivable', 'bn' => 'প্রাপ্য ফি'], 'parent' => '1100'],
        ['code' => '2150', 'name' => ['en' => 'Fees received in advance', 'bn' => 'অগ্রিম প্রাপ্ত ফি'], 'parent' => '2100'],
        ['code' => '4110', 'name' => ['en' => 'Tuition fees', 'bn' => 'বেতন (টিউশন ফি)'], 'parent' => '4000'],
        ['code' => '4120', 'name' => ['en' => 'Admission fees', 'bn' => 'ভর্তি ফি'], 'parent' => '4000'],
        ['code' => '4130', 'name' => ['en' => 'Examination fees', 'bn' => 'পরীক্ষার ফি'], 'parent' => '4000'],
        ['code' => '4140', 'name' => ['en' => 'Transport fees', 'bn' => 'পরিবহন ফি'], 'parent' => '4000'],
        ['code' => '4150', 'name' => ['en' => 'Late fines', 'bn' => 'বিলম্ব জরিমানা'], 'parent' => '4000'],
        ['code' => '4190', 'name' => ['en' => 'Fee discounts and scholarships', 'bn' => 'ফি ছাড় ও বৃত্তি'], 'parent' => '4000'],
        ['code' => '5210', 'name' => ['en' => "Teachers' salaries", 'bn' => 'শিক্ষকদের বেতন'], 'parent' => '5000'],
        ['code' => '5510', 'name' => ['en' => 'Teaching materials', 'bn' => 'শিক্ষা উপকরণ'], 'parent' => '5000'],
        ['code' => '5520', 'name' => ['en' => 'Examination expenses', 'bn' => 'পরীক্ষা সংক্রান্ত ব্যয়'], 'parent' => '5000'],
    ],
    // Schools sell no goods.
    'remove' => ['4100', '5100'],
    // Fees owed by families go to their own receivable account.
    'postings' => [
        'accounting.receivable' => '1145', 'crm.sales' => '4120',
        'education_fees.tuition_income' => '4110', 'education_fees.admission_income' => '4120',
        'education_fees.exam_income' => '4130', 'education_fees.transport_income' => '4140',
        'education_fees.other_income' => '4900',
        'education_fees.receivable' => '1145', 'education_fees.fine_income' => '4150',
    ],
];
