<?php

return [
    'valuation_method' => [
        'label' => 'Stock valuation method',
        'description' => 'How the cost of stock is calculated.',
        'options' => [
            'FIFO' => 'First in, first out (FIFO)',
            'weighted_average' => 'Weighted average',
        ],
    ],
    'allow_negative_stock' => [
        'label' => 'Allow negative stock',
        'description' => 'Allow issuing items that are not in stock.',
    ],
    'low_stock_alert_percent' => [
        'label' => 'Low stock alert (%)',
        'description' => 'Alert when stock falls below this percent of the reorder level.',
    ],

    'categories' => [
        'stock' => 'Stock',
        'valuation' => 'Valuation',
    ],
];
