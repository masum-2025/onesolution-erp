<?php

// Inventory rule labels.
return [
    'valuation_method' => [
        'label' => 'মজুদ মূল্যায়নের পদ্ধতি',
        'description' => 'বের হওয়া মজুদের খরচ কীভাবে হিসাব হবে। প্রতিটি গুদাম তার মজুদ শুরুর পদ্ধতিই রাখে।',
        'options' => [
            'FIFO' => 'আগে এলে আগে যায় (FIFO)',
            'weighted_average' => 'গড় দাম',
        ],
    ],
    'allow_negative_stock' => [
        'label' => 'ঋণাত্মক মজুদ চলবে',
        'description' => 'খাতার চেয়ে বেশি বের করা যাবে (শেষ জানা দামে হিসাব)।',
    ],
    'low_stock_alert_percent' => [
        'label' => 'কমে আসছে (%)',
        'description' => 'মজুদ reorder level-এর এই শতাংশের মধ্যে এলে "কমে আসছে" দেখাবে।',
    ],
    'adjustment_approval_above' => [
        'label' => 'এর বেশি সমন্বয়ে অনুমোদন লাগবে',
        'description' => 'এর চেয়ে বেশি মূল্যের সমন্বয় অন্য কারও অনুমোদনের অপেক্ষায় থাকবে। খালি: কখনো নয়।',
    ],
    'expiry_alert_days' => [
        'label' => 'মেয়াদ শেষের সতর্কতা (দিন)',
        'description' => 'এত দিনের মধ্যে মেয়াদ শেষ হওয়া batch দেখানো ও জানানো হবে।',
    ],
    'number_prefixes' => [
        'label' => 'নথির নম্বরের শুরু',
        'description' => 'প্রতিটি ধরনের নথির নম্বরের আগের অক্ষর (গ্রহণ, প্রদান, স্থানান্তর, সমন্বয়, গণনা)।',
    ],
    'categories' => [
        'stock' => 'মজুদ',
        'valuation' => 'মূল্যায়ন',
        'approval' => 'অনুমোদন',
        'numbering' => 'নম্বর',
    ],
];
