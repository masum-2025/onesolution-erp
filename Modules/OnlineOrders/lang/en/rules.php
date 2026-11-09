<?php

// Online orders rule labels.
return [
    'number_prefix' => [
        'label' => 'Order number prefix',
        'description' => 'Letters before each order number, e.g. OO gives OO-2026-000001.',
    ],
    'prices_include_tax' => [
        'label' => 'Prices include VAT',
        'description' => 'Item prices already include VAT (it is taken out of them); otherwise it is added on the order.',
    ],
    'cod_allowed' => [
        'label' => 'Cash on delivery',
        'description' => 'Customers may pay the courier when the order arrives. Off: orders are paid before they are sent.',
    ],
    'delivery_zones' => [
        'label' => 'Delivery areas and charges',
        'description' => 'Each area with a code, its name and what delivery there costs. An order picks one; without areas the charge is typed on the order.',
    ],
    'max_discount_percent' => [
        'label' => 'Largest discount staff give (%)',
        'description' => 'Of an order\'s goods. A bigger discount needs a supervisor.',
    ],
    'return_days' => [
        'label' => 'Days to send an order back',
        'description' => 'After delivery. Later, the order can no longer be taken back.',
    ],
    'online_payment' => [
        'label' => 'Paid online before sending',
        'description' => 'What a customer pays through a payment link (needs Online payments and your merchant account).',
        'options' => [
            'none' => 'Nothing (no payment link)',
            'delivery' => 'The delivery charge (the rest on delivery)',
            'full' => 'The whole order',
        ],
    ],
    'pay_link_hours' => [
        'label' => 'Payment link works for (hours)',
        'description' => 'After this, the link no longer opens; a new one can be sent.',
    ],
];
