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
    'shift_minutes' => [
        'label' => 'Minutes a line works in a day',
        'description' => 'Used when the supervisor does not write the day\'s minutes. 480 = 8 hours.',
    ],
    'target_efficiency_bp' => [
        'label' => 'Target efficiency',
        'description' => 'An hour\'s target is set at this efficiency, in hundredths of a per cent: 6000 = 60%.',
    ],
    'efficiency_counts_helpers' => [
        'label' => 'Count helpers in efficiency',
        'description' => 'On: helpers\' minutes are available minutes too. Off: operators only.',
    ],
    'final_aql' => [
        'label' => 'AQL of final inspections',
        'description' => 'The sample size and the most defects a passing sample may have come from this column of the AQL table (general level II).',
    ],
    'ship_needs_final_pass' => [
        'label' => 'Ship only after a passed final inspection',
        'description' => 'On: an order cannot be marked shipped until its last final inspection passed.',
    ],
    'dhu_warn_bp' => [
        'label' => 'DHU that rings the bell',
        'description' => 'A line\'s end-line DHU today at or above this, in hundredths: 500 = 5 defects per hundred pieces.',
    ],
    'piece_rate_per' => [
        'label' => 'Piece rates are written per',
        'description' => 'Piece: pay = pieces × rate. Dozen: pay = pieces × rate ÷ 12, half up.',
    ],
    'piece_ticket_self' => [
        'label' => 'People may write their own piece tickets',
        'description' => 'Off: a supervisor writes an operator\'s tickets (pay is checked by someone else).',
    ],
    'shipment_number_prefix' => [
        'label' => 'Shipment number prefix',
        'description' => 'Shipments are numbered PREFIX-YEAR-00001.',
    ],
    'paper_language' => [
        'label' => 'Language of shipping papers',
        'description' => 'Packing lists and commercial invoices print in this language (a code such as en), whatever the screen\'s. The person printing can still choose another.',
    ],
    'categories' => [
        'materials' => 'Materials',
        'orders' => 'Orders',
        'floor' => 'Floor',
        'efficiency' => 'Efficiency',
        'quality' => 'Quality',
        'pay' => 'Pay',
    ],
];
