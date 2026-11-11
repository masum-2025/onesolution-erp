<?php

/*
| Chart of accounts template "pharmacy": the retail chart plus medicine
| sales, expired and damaged stock, and money owed to suppliers. Names and
| codes are a starting point for an accountant.
*/

return [
    'key' => 'pharmacy',
    'extends' => 'retail',
    'accounts' => [
        ['code' => '4130', 'name' => ['en' => 'Medicine sales', 'bn' => 'ওষুধ বিক্রি'], 'parent' => '4000'],
        ['code' => '5170', 'name' => ['en' => 'Expired and damaged medicines', 'bn' => 'মেয়াদোত্তীর্ণ ও নষ্ট ওষুধ'], 'parent' => '5000'],
    ],
    // Counter sales are medicine sales; stock written off is mostly expiry.
    'postings' => ['pos.sales' => '4130', 'inventory.adjustment' => '5170'],
];
