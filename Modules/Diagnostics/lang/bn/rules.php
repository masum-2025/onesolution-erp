<?php

return [
    'report_turnaround_hours' => [
        'label' => 'রিপোর্ট দিতে লাগে (ঘণ্টা)',
        'description' => 'Test-এ আলাদা না বললে নমুনা নেওয়ার এত ঘণ্টা পরে রিপোর্ট দেওয়ার কথা।',
    ],
    'require_verification' => [
        'label' => 'দ্বিতীয় একজন ফল যাচাই করবেন',
        'description' => 'যিনি ফল লিখেছেন তিনি ছাড়া অন্য কেউ সই করলে তবেই রিপোর্ট যায়। বন্ধ থাকলে ফল লিখলেই চলে (খুব ছোট ল্যাব)।',
    ],
    'number_prefixes' => [
        'label' => 'নম্বরের শুরুর অক্ষর',
        'description' => 'Order আর নমুনার নম্বরের আগে যে অক্ষর বসবে, যেমন D000001 আর S0000001।',
    ],
    'referral_commission_percent' => [
        'label' => 'Referral ডাক্তারের কমিশন (%)',
        'description' => 'বিল চূড়ান্ত হলে order-এর test-গুলোর ছাড়ের পরের দামের (কর বাদে) এই অংশ। ডাক্তারের নিজের % দেওয়া থাকলে সেটাই আগে।',
    ],
    'report_link_hours' => [
        'label' => 'রিপোর্টের link কত ঘণ্টা চলবে',
        'description' => 'যাচাই করা রিপোর্টের link এতক্ষণ login ছাড়া খোলে; তারপর জানায় link পুরোনো।',
    ],
    'send_report_sms' => [
        'label' => 'রিপোর্ট তৈরি হলে link SMS করা',
        'description' => 'Order-এর সব test যাচাই হওয়ামাত্র রোগী রিপোর্টের link SMS-এ পান (SMS চালু আর মোবাইল নম্বর থাকতে হবে)।',
    ],
    'categories' => [
        'reports' => 'রিপোর্ট',
        'numbering' => 'নম্বর',
        'commission' => 'কমিশন',
        'general' => 'সাধারণ',
    ],
];
