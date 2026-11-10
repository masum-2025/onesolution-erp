<?php

/*
| Pharmacy (php artisan make:erp-module database/module-definitions/pharmacy.php).
| Only the simple lists come from here (generics, manufacturers); medicines, selling,
| the controlled-drug register and supplier returns are written by hand in the module.
*/

return [
    'key' => 'pharmacy',
    'name' => ['en' => 'Pharmacy', 'bn' => 'ফার্মেসি'],
    'description' => ['en' => 'Medicine shop: medicines by brand and generic, selling by piece, strip or box within MRP, expiry and the controlled-drug register.', 'bn' => 'ওষুধের দোকান: brand ও generic অনুযায়ী ওষুধ, MRP-র মধ্যে পিস, পাতা বা বক্সে বিক্রি, মেয়াদ আর নিয়ন্ত্রিত ওষুধের খাতা।'],
    'requires' => ['inventory', 'pos'],
    'icon' => 'pill',
    'entities' => [
        'generic' => [
            'label' => ['en' => 'Generic', 'bn' => 'Generic'],
            'plural' => ['en' => 'Generics', 'bn' => 'Generic'],
            'title' => 'name',
            'search' => ['drug_class'],
            'filters' => ['is_controlled', 'in_use'],
            'columns' => ['name', 'drug_class', 'is_controlled', 'in_use'],
            'fields' => [
                'name' => ['type' => 'translatable', 'required' => true, 'max' => 150, 'label' => ['en' => 'Generic name', 'bn' => 'Generic নাম']],
                'drug_class' => ['type' => 'text', 'max' => 60, 'label' => ['en' => 'Drug class', 'bn' => 'ওষুধের শ্রেণি']],
                'is_controlled' => ['type' => 'boolean', 'label' => ['en' => 'Controlled medicine', 'bn' => 'নিয়ন্ত্রিত ওষুধ']],
                'controlled_schedule' => ['type' => 'text', 'max' => 20, 'label' => ['en' => 'Schedule (if controlled)', 'bn' => 'তফসিল (নিয়ন্ত্রিত হলে)']],
                'in_use' => ['type' => 'boolean', 'label' => ['en' => 'In use', 'bn' => 'চালু']],
            ],
        ],
        'manufacturer' => [
            'label' => ['en' => 'Manufacturer', 'bn' => 'প্রস্তুতকারক'],
            'plural' => ['en' => 'Manufacturers', 'bn' => 'প্রস্তুতকারক'],
            'title' => 'name',
            'search' => ['country'],
            'filters' => ['in_use'],
            'columns' => ['name', 'country', 'in_use'],
            'fields' => [
                'name' => ['type' => 'translatable', 'required' => true, 'max' => 150, 'label' => ['en' => 'Company name', 'bn' => 'কোম্পানির নাম']],
                'country' => ['type' => 'text', 'max' => 2, 'label' => ['en' => 'Country code (e.g. BD)', 'bn' => 'দেশের code (যেমন BD)']],
                'in_use' => ['type' => 'boolean', 'label' => ['en' => 'In use', 'bn' => 'চালু']],
            ],
        ],
    ],
    'rules' => [
        [
            'name' => 'near_expiry_days',
            'type' => 'integer',
            'default' => 30,
            'min' => 0,
            'max' => 365,
            'label' => ['en' => 'Near expiry (days)', 'bn' => 'মেয়াদ শেষের সতর্কতা (দিন)'],
            'description' => ['en' => 'Medicines expiring within this many days show in red at the counter and in stock.', 'bn' => 'এত দিনের মধ্যে মেয়াদ শেষ হলে বিক্রির পাতায় আর মজুদে লাল দেখায়।'],
        ],
    ],
];
