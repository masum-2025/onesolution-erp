<?php

// Rule labels, key = part after "inpatient.".
return [
    'bed_day_starts' => [
        'label' => 'বেডের দিন শুরু হয়',
        'description' => 'রাত ১২টায় হলে ভর্তির দিন গোনা হয়, ছাড়ার দিন নয়; ভর্তির সময় থেকে হলে প্রতিটি শুরু হওয়া ২৪ ঘণ্টা একদিন।',
    ],
    'discharge_requires_clearance' => [
        'label' => 'বিল পরিশোধ হলে তবেই ছাড়পত্র',
        'description' => 'বিলে কিছু বাকি না থাকলে তবেই রোগীকে ছাড়পত্র দেওয়া হয়।',
    ],
    'min_advance' => [
        'label' => 'ভর্তির আগে অগ্রিম',
        'description' => 'ভর্তির আগে রোগীর কত অগ্রিম (রোগীর বিলে) থাকতে হবে। খালি = লাগবে না।',
    ],
    'number_prefix' => [
        'label' => 'ভর্তি নম্বরের শুরুর অক্ষর',
        'description' => 'ভর্তি নম্বরের আগের অক্ষর, যেমন IP000123।',
    ],
    'categories' => [
        'charges' => 'ভাড়া ও অগ্রিম',
        'discharge' => 'ছাড়পত্র',
        'general' => 'সাধারণ',
    ],
];
