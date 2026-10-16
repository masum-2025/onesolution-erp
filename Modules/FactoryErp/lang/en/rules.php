<?php

// Rule labels, key = part after "factory_erp.".
return [
    'consumption_basis' => [
        'label' => 'Consumption written per',
        'description' => 'A style\'s bill of materials says how much is used per dozen (garments) or per piece. Kept for a style when it is made.',
    ],
    'default_wastage_bp' => [
        'label' => 'Wastage a new material starts with',
        'description' => 'In hundredths of a per cent: 500 = 5%. Each line of a bill of materials can have its own.',
    ],
    'order_currency' => [
        'label' => 'Currency of new orders',
        'description' => 'Export orders are usually priced in the buyer\'s currency (three letters, e.g. USD).',
    ],
    'order_number_prefix' => [
        'label' => 'Order number prefix',
        'description' => 'Orders are numbered PREFIX-YEAR-00001.',
    ],
    'ship_warning_days' => [
        'label' => 'Days ahead to watch ship dates',
        'description' => 'Orders shipping within this many days show on the home; confirmed ones not in production yet ring the bell.',
    ],
    'categories' => [
        'materials' => 'Materials',
        'orders' => 'Orders',
    ],
];
