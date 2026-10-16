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
    'menu_import' => 'ফাইল থেকে শিক্ষার্থী আনা',
    'menu_documents' => 'আইডি কার্ড ও সনদ',
    'menu_designs' => 'কার্ড ও সনদের নকশা',
    'new_admission' => 'নতুন ভর্তি',
    'new_student' => 'নতুন শিক্ষার্থী',
    // প্রতিটি ধরনের প্রতিষ্ঠানের menu-র শব্দ (manifest-এর "sector_labels")।
    'sector' => [
        'school' => ['menu_top' => 'শিক্ষার্থী ও শ্রেণি', 'menu_sections' => 'শ্রেণি ও শাখা'],
        'madrasa' => ['menu_top' => 'শিক্ষার্থী ও শ্রেণি', 'menu_sections' => 'শ্রেণি ও শাখা'],
        'college' => ['menu_top' => 'শিক্ষার্থী ও বর্ষ', 'menu_sections' => 'বর্ষ ও গ্রুপ'],
        'university' => ['menu_top' => 'শিক্ষার্থী ও সেমিস্টার', 'menu_sections' => 'সেমিস্টার ও সেকশন', 'menu_admissions' => 'ভর্তি (আবেদন)'],
        'coaching' => ['menu_top' => 'শিক্ষার্থী ও ব্যাচ', 'menu_sections' => 'কোর্স ও ব্যাচ'],
    ],
];
