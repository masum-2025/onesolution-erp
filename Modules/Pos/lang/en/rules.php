<?php

// POS rule labels.
return [
    'prices_include_tax' => [
        'label' => 'Prices include VAT',
        'description' => 'Shelf prices already include VAT (it is taken out of them); otherwise it is added at the till.',
    ],
    'max_discount_percent' => [
        'label' => 'Largest discount a cashier gives (%)',
        'description' => 'Of a sale. A bigger discount needs a supervisor.',
    ],
    'cash_variance_allowed' => [
        'label' => 'Cash difference allowed at closing',
        'description' => 'A shift whose counted cash is off by no more than this closes; otherwise a supervisor reviews it. Empty: every difference is reviewed.',
    ],
    'return_days' => [
        'label' => 'Days to bring goods back',
        'description' => 'A sale can be returned within this many days.',
    ],
    'offline_sales' => [
        'label' => 'Sell while offline',
        'description' => 'Counters keep selling without internet; sales are sent when back online.',
    ],
    'number_prefixes' => [
        'label' => 'Receipt number prefixes',
        'description' => 'The letters before sale and return numbers.',
    ],
    'categories' => [
        'tax' => 'Tax',
        'selling' => 'Selling',
        'cash' => 'Cash',
        'numbering' => 'Numbering',
    ],
];
