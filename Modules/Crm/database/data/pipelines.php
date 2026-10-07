<?php

/*
| The pipeline a company starts with, by its sector ("*" for any other).
| Data, not code: a new sector adds an entry here; each company then changes
| its own stages in the CRM settings. probability_bp: 10000 = sure.
| outcome: open | won | lost (one won and one lost stage each).
*/

return [
    '*' => [
        'key' => 'sales',
        'name' => ['en' => 'Sales', 'bn' => 'বিক্রয়'],
        'stages' => [
            ['key' => 'new', 'name' => ['en' => 'New lead', 'bn' => 'নতুন লিড'], 'probability_bp' => 1000],
            ['key' => 'contacted', 'name' => ['en' => 'Contacted', 'bn' => 'যোগাযোগ হয়েছে'], 'probability_bp' => 2500],
            ['key' => 'quoted', 'name' => ['en' => 'Quotation sent', 'bn' => 'কোটেশন পাঠানো'], 'probability_bp' => 5000],
            ['key' => 'negotiation', 'name' => ['en' => 'Negotiation', 'bn' => 'দর-কষাকষি'], 'probability_bp' => 7500],
            ['key' => 'won', 'name' => ['en' => 'Won', 'bn' => 'জিতেছে'], 'probability_bp' => 10000, 'outcome' => 'won'],
            ['key' => 'lost', 'name' => ['en' => 'Lost', 'bn' => 'হারিয়েছে'], 'probability_bp' => 0, 'outcome' => 'lost'],
        ],
    ],
    'school' => [
        'key' => 'admissions',
        'name' => ['en' => 'Admissions', 'bn' => 'ভর্তি'],
        'stages' => [
            ['key' => 'inquiry', 'name' => ['en' => 'Inquiry', 'bn' => 'খোঁজখবর'], 'probability_bp' => 1000],
            ['key' => 'visit', 'name' => ['en' => 'Campus visit', 'bn' => 'ক্যাম্পাস দেখা'], 'probability_bp' => 3000],
            ['key' => 'test', 'name' => ['en' => 'Admission test', 'bn' => 'ভর্তি পরীক্ষা'], 'probability_bp' => 6000],
            ['key' => 'offer', 'name' => ['en' => 'Offer made', 'bn' => 'ভর্তির প্রস্তাব'], 'probability_bp' => 8000],
            ['key' => 'admitted', 'name' => ['en' => 'Admitted', 'bn' => 'ভর্তি হয়েছে'], 'probability_bp' => 10000, 'outcome' => 'won'],
            ['key' => 'withdrawn', 'name' => ['en' => 'Did not join', 'bn' => 'ভর্তি হয়নি'], 'probability_bp' => 0, 'outcome' => 'lost'],
        ],
    ],
    'retail' => [
        'key' => 'orders',
        'name' => ['en' => 'Bulk orders', 'bn' => 'পাইকারি অর্ডার'],
        'stages' => [
            ['key' => 'request', 'name' => ['en' => 'Request', 'bn' => 'চাহিদা'], 'probability_bp' => 2000],
            ['key' => 'quoted', 'name' => ['en' => 'Quoted', 'bn' => 'দর দেওয়া হয়েছে'], 'probability_bp' => 5000],
            ['key' => 'confirmed', 'name' => ['en' => 'Confirmed', 'bn' => 'নিশ্চিত'], 'probability_bp' => 9000],
            ['key' => 'delivered', 'name' => ['en' => 'Delivered', 'bn' => 'ডেলিভারি হয়েছে'], 'probability_bp' => 10000, 'outcome' => 'won'],
            ['key' => 'cancelled', 'name' => ['en' => 'Cancelled', 'bn' => 'বাতিল'], 'probability_bp' => 0, 'outcome' => 'lost'],
        ],
    ],
];
