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
    'over_issue' => [
        'label' => 'More material than an order needs',
        'description' => 'Warn: the store keeper is asked again and may say yes. Block: never more than the bill of materials works out.',
    ],
    'over_cut_bp' => [
        'label' => 'Extra pieces that may be cut',
        'description' => 'Above the order, per size and colour, in hundredths of a per cent: 300 = 3%.',
    ],
    'output_by' => [
        'label' => 'Lines write output',
        'description' => 'Hour: every hour (the hour is chosen). Day: once a day.',
    ],
    'reject_warn_bp' => [
        'label' => 'Rejects that ring the bell',
        'description' => 'A line\'s rejects today at or above this share of what it made, in hundredths of a per cent: 500 = 5%.',
    ],
    'categories' => [
        'materials' => 'Materials',
        'orders' => 'Orders',
        'floor' => 'Floor',
    ],
];
