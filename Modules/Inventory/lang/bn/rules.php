<?php

return [
    'valuation_method' => [
        'label' => 'মজুদ মূল্যায়ন পদ্ধতি',
        'description' => 'মজুদের খরচ কীভাবে হিসাব করা হবে।',
        'options' => [
            'FIFO' => 'আগে আসা, আগে যাওয়া (FIFO)',
            'weighted_average' => 'ভারিত গড়',
        ],
    ],
    'allow_negative_stock' => [
        'label' => 'ঋণাত্মক মজুদ অনুমোদন',
        'description' => 'মজুদে না থাকা পণ্যও দেওয়া যাবে।',
    ],
    'low_stock_alert_percent' => [
        'label' => 'কম মজুদের সতর্কতা (%)',
        'description' => 'পুনঃঅর্ডার স্তরের এত শতাংশের নিচে নামলে সতর্ক করা হবে।',
    ],

    'categories' => [
        'stock' => 'মজুদ',
        'valuation' => 'মূল্যায়ন',
    ],
];
