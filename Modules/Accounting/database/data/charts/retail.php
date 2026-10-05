<?php

/*
| Chart of accounts template "retail": the general chart plus shop sales and
| stock losses. Names and codes are a starting point for an accountant.
*/

return [
    'key' => 'retail',
    'extends' => 'general',
    'accounts' => [
        ['code' => '1115', 'name' => ['en' => 'Cash in tills', 'bn' => 'ক্যাশ কাউন্টারে নগদ'], 'parent' => '1100'],
        ['code' => '1135', 'name' => ['en' => 'Card and wallet settlements due', 'bn' => 'কার্ড ও ওয়ালেট থেকে প্রাপ্য'], 'parent' => '1100'],
        ['code' => '4110', 'name' => ['en' => 'Sales returns', 'bn' => 'বিক্রয় ফেরত'], 'parent' => '4000'],
        ['code' => '4120', 'name' => ['en' => 'Sales discounts', 'bn' => 'বিক্রয় বাট্টা'], 'parent' => '4000'],
        ['code' => '5150', 'name' => ['en' => 'Stock shrinkage', 'bn' => 'মজুদ ঘাটতি'], 'parent' => '5000'],
        ['code' => '5160', 'name' => ['en' => 'Card and wallet fees', 'bn' => 'কার্ড ও ওয়ালেট চার্জ'], 'parent' => '5000'],
    ],
    // Shrinkage, breakage and count differences.
    'postings' => ['inventory.adjustment' => '5150'],
];
