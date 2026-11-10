<?php

// Rule labels, key = part after "patient_billing.".
return [
    'discount_approval_above_percent' => [
        'label' => 'Discounts above this need approval (%)',
        'description' => 'A larger discount on a bill waits for someone else to approve it. Panel discounts never need approval.',
    ],
    'allow_due' => [
        'label' => 'Allow dues',
        'description' => 'A bill may be finalized before it is fully paid (the rest stays due). Off: take the full amount first. Panel bills always may.',
    ],
    'prices_include_tax' => [
        'label' => 'Prices include VAT',
        'description' => 'Service prices already include VAT. Off: VAT is added on top. Old bills keep how they were made.',
    ],
    'require_cash_day' => [
        'label' => 'Cash only with an open cash day',
        'description' => 'A cashier opens their day before taking cash, and closes it by counting the cash.',
    ],
    'cash_variance_allowed' => [
        'label' => 'Allowed cash difference (in paisa / cents)',
        'description' => 'A day whose counted cash differs by more than this waits for a supervisor.',
    ],
    'number_prefixes' => [
        'label' => 'Number prefixes',
        'description' => 'Letters before bill, money receipt and refund numbers, e.g. B000001, MR000001, RF000001.',
    ],
    'categories' => [
        'discounts' => 'Discounts',
        'billing' => 'Billing',
        'cash' => 'Cash',
        'numbering' => 'Numbering',
    ],
];
