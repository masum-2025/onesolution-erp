<?php

/*
| Chart of accounts template "factory": the general chart plus stock stages
| and production costs. Names and codes are a starting point for an accountant.
*/

return [
    'key' => 'factory',
    'extends' => 'general',
    'accounts' => [
        ['code' => '1151', 'name' => ['en' => 'Raw materials', 'bn' => 'কাঁচামাল'], 'parent' => '1100'],
        ['code' => '1152', 'name' => ['en' => 'Work in progress', 'bn' => 'প্রক্রিয়াধীন পণ্য'], 'parent' => '1100'],
        ['code' => '1153', 'name' => ['en' => 'Finished goods', 'bn' => 'তৈরি পণ্য'], 'parent' => '1100'],
        ['code' => '5110', 'name' => ['en' => 'Direct materials used', 'bn' => 'ব্যবহৃত প্রত্যক্ষ কাঁচামাল'], 'parent' => '5000'],
        ['code' => '5120', 'name' => ['en' => 'Direct labour', 'bn' => 'প্রত্যক্ষ শ্রম'], 'parent' => '5000'],
        ['code' => '5130', 'name' => ['en' => 'Factory overheads', 'bn' => 'কারখানার উপরিব্যয়'], 'parent' => '5000'],
        ['code' => '5140', 'name' => ['en' => 'Machine maintenance', 'bn' => 'যন্ত্রপাতি রক্ষণাবেক্ষণ'], 'parent' => '5000'],
    ],
    // Stock is kept by stage instead of one inventory account.
    'remove' => ['1150'],
    // Items bought in are raw materials until a production module moves them.
    'postings' => ['inventory.stock' => '1151', 'inventory.cogs' => '5110'],
];
