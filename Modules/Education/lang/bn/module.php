<?php

// শিক্ষা মডিউলের নাম ও মেনু।
return [
    'name' => 'শিক্ষা',
    'description' => 'স্কুল, কলেজ, বিশ্ববিদ্যালয়, মাদ্রাসা ও কোচিং: প্রোগ্রাম, সেশন, শিক্ষার্থী, অভিভাবক ও ভর্তি।',
    'menu' => 'শিক্ষা',
    'menu_overview' => 'সারসংক্ষেপ',
    'menu_students' => 'শিক্ষার্থী',
    'menu_admissions' => 'ভর্তি',
    'menu_sections' => 'শাখা',
    'menu_promotions' => 'প্রমোশন',
    'menu_structure' => 'প্রোগ্রাম, শ্রেণি ও সেশন',
    'menu_fields' => 'নিজের ঘর',
    'menu_import' => 'শিক্ষার্থী ইমপোর্ট',
    'menu_documents' => 'আইডি কার্ড ও সনদ',
    'menu_designs' => 'ডকুমেন্টের নকশা',
    'new_admission' => 'নতুন ভর্তি',
    'new_student' => 'নতুন শিক্ষার্থী',
    // প্রতিটি ধরনের প্রতিষ্ঠানের menu-র শব্দ (manifest-এর "sector_labels")।
    'sector' => [
        'school' => ['menu_sections' => 'শ্রেণি ও শাখা', 'menu_promotions' => 'পরের শ্রেণিতে তোলা'],
        'madrasa' => ['menu_sections' => 'শ্রেণি ও শাখা', 'menu_promotions' => 'পরের শ্রেণিতে তোলা'],
        'college' => ['menu_sections' => 'বর্ষ ও গ্রুপ', 'menu_promotions' => 'পরের বর্ষে তোলা'],
        'university' => ['menu_sections' => 'সেমিস্টার ও সেকশন', 'menu_promotions' => 'পরের সেমিস্টারে তোলা', 'menu_admissions' => 'ভর্তি (আবেদন)'],
        'coaching' => ['menu_sections' => 'কোর্স ও ব্যাচ'],
    ],
];
