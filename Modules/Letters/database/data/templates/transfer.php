<?php

// Ready-made transfer letter.
return [
    'key' => 'transfer',
    'kind' => 'transfer',
    'code' => 'TRF',
    'name' => ['en' => 'Transfer letter', 'bn' => 'বদলির চিঠি'],
    'description' => ['en' => 'Transfers the employee to another branch, department or place from a date.', 'bn' => 'নির্দিষ্ট তারিখ থেকে কর্মীকে অন্য শাখা, বিভাগ বা স্থানে বদলি।'],
    'page' => ['size' => 'A4', 'margin_mm' => 20],
    'letterhead' => ['style' => 'name', 'logo' => true, 'align' => 'center', 'address' => []],
    'inputs' => [
        ['key' => 'new_place', 'type' => 'text', 'required' => true, 'label' => ['en' => 'Transferred to', 'bn' => 'যেখানে বদলি']],
        ['key' => 'effective_date', 'type' => 'date', 'required' => true, 'label' => ['en' => 'Report there on', 'bn' => 'যোগদানের তারিখ']],
    ],
    'bodies' => [
        'en' => [
            ['type' => 'paragraph', 'text' => "Ref: {letter.number}\nDate: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\n{employee.designation}\nEmployee code: {employee.code}"],
            ['type' => 'heading', 'text' => 'Subject: Transfer', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'Dear {employee.name},'],
            ['type' => 'paragraph', 'text' => 'In the interest of the company, you are transferred to **{input.new_place}** and are asked to report there on **{input.effective_date}**.', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'Please hand over your present duties before you leave. Your designation, salary and other terms remain the same.', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'Authorised signatory, {company.name}', 'align' => 'start'],
        ],
        'bn' => [
            ['type' => 'paragraph', 'text' => "সূত্র: {letter.number}\nতারিখ: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\n{employee.designation}\nকর্মী কোড: {employee.code}"],
            ['type' => 'heading', 'text' => 'বিষয়: বদলি', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'প্রিয় {employee.name},'],
            ['type' => 'paragraph', 'text' => 'প্রতিষ্ঠানের প্রয়োজনে আপনাকে **{input.new_place}**-এ বদলি করা হলো এবং **{input.effective_date}** তারিখে সেখানে যোগ দিতে বলা হলো।', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'যাওয়ার আগে আপনার বর্তমান দায়িত্ব বুঝিয়ে দিন। আপনার পদ, বেতন ও অন্যান্য শর্ত অপরিবর্তিত থাকবে।', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'অনুমোদিত স্বাক্ষরকারী, {company.name}', 'align' => 'start'],
        ],
    ],
];
