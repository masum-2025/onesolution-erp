<?php

// Inventory rule labels.
return [
    'valuation_method' => [
        'label' => 'Stock valuation method',
        'description' => 'How the cost of stock going out is worked out. A warehouse keeps the method its stock started with.',
        'options' => [
            'FIFO' => 'First in, first out (FIFO)',
            'weighted_average' => 'Weighted average',
        ],
    ],
    'allow_negative_stock' => [
        'label' => 'Allow negative stock',
        'description' => 'Allow taking out more than the books show (counted later at the last known cost).',
    ],
    'low_stock_alert_percent' => [
        'label' => 'Running low (%)',
        'description' => 'Show an item as running low when its stock is within this percent above its reorder level.',
    ],
    'adjustment_approval_above' => [
        'label' => 'Adjustments needing approval above',
        'description' => 'An adjustment worth more than this waits for someone else to approve it. Empty: never.',
    ],
    'expiry_alert_days' => [
        'label' => 'Warn of expiry (days)',
        'description' => 'Batches expiring within this many days are shown and announced.',
    ],
    'number_prefixes' => [
        'label' => 'Document number prefixes',
        'description' => 'The letters before each document number, per kind (receipt, issue, transfer, adjustment, count).',
    ],
    'categories' => [
        'stock' => 'Stock',
        'valuation' => 'Valuation',
        'approval' => 'Approvals',
        'numbering' => 'Numbering',
    ],
];
