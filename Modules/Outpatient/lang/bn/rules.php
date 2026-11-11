<?php

// Rule labels, key = part after "outpatient.".
return [
    'daily_serial_limit' => [
        'label' => 'এক বেলায় সিরিয়াল (সাধারণ)',
        'description' => 'সময়সূচিতে আলাদা না বললে এক বেলায় একজন ডাক্তার কতজন রোগী দেখেন।',
    ],
    'follow_up_free_days' => [
        'label' => 'এত দিনের মধ্যে ফলো-আপ ফ্রি',
        'description' => 'শেষ হওয়া দেখানোর এত দিনের মধ্যে একই ডাক্তারের কাছে এলে ফি লাগে না। ০ = কখনো ফ্রি নয়।',
    ],
    'serial_sms' => [
        'label' => 'রোগীকে সিরিয়াল SMS করুন',
        'description' => 'সিরিয়াল দিলে রোগী ডাক্তার, তারিখ আর নম্বরসহ SMS পান (SMS চালু থাকতে হবে)।',
    ],
    'prescription_language' => [
        'label' => 'প্রেসক্রিপশনের ভাষা',
        'description' => 'প্রেসক্রিপশনে নির্দেশনা কোন ভাষায় ছাপা হবে।',
    ],
    'categories' => [
        'serials' => 'সিরিয়াল',
        'fees' => 'ফি',
        'prescriptions' => 'প্রেসক্রিপশন',
        'general' => 'সাধারণ',
    ],
];
