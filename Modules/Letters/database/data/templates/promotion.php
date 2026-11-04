<?php

// Ready-made promotion letter: the new designation is HRM's (change it there first).
return [
    'key' => 'promotion',
    'kind' => 'promotion',
    'code' => 'PRM',
    'name' => ['en' => 'Promotion letter', 'bn' => 'পদোন্নতির চিঠি'],
    'description' => ['en' => 'Promotes the employee to their new designation (change it in HRM first).', 'bn' => 'কর্মীকে নতুন পদে পদোন্নতি (আগে HRM-এ পদ বদলান)।'],
    'page' => ['size' => 'A4', 'margin_mm' => 20],
    'letterhead' => ['style' => 'name', 'logo' => true, 'align' => 'center', 'address' => []],
    'inputs' => [
        ['key' => 'previous_designation', 'type' => 'text', 'required' => true, 'label' => ['en' => 'Designation before', 'bn' => 'আগের পদ']],
        ['key' => 'effective_date', 'type' => 'date', 'required' => true, 'label' => ['en' => 'Promoted from', 'bn' => 'যে তারিখ থেকে পদোন্নতি']],
    ],
    'bodies' => [
        'en' => [
            ['type' => 'paragraph', 'text' => "Ref: {letter.number}\nDate: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\nEmployee code: {employee.code}"],
            ['type' => 'heading', 'text' => 'Subject: Promotion', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'Dear {employee.name},'],
            ['type' => 'paragraph', 'text' => 'We are pleased to promote you from {input.previous_designation} to **{employee.designation}** with effect from **{input.effective_date}**.', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'Your new responsibilities will be explained by your manager. All other terms of your employment remain the same. Congratulations, and we wish you every success in your new role.', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'Authorised signatory, {company.name}', 'align' => 'start'],
        ],
        'bn' => [
            ['type' => 'paragraph', 'text' => "সূত্র: {letter.number}\nতারিখ: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\nকর্মী কোড: {employee.code}"],
            ['type' => 'heading', 'text' => 'বিষয়: পদোন্নতি', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'প্রিয় {employee.name},'],
            ['type' => 'paragraph', 'text' => 'আনন্দের সাথে জানাচ্ছি যে **{input.effective_date}** থেকে আপনাকে {input.previous_designation} পদ থেকে **{employee.designation}** পদে পদোন্নতি দেওয়া হলো।', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'আপনার নতুন দায়িত্ব আপনার ম্যানেজার বুঝিয়ে দেবেন। চাকরির অন্যান্য সব শর্ত অপরিবর্তিত থাকবে। অভিনন্দন, নতুন দায়িত্বে সাফল্য কামনা করি।', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'অনুমোদিত স্বাক্ষরকারী, {company.name}', 'align' => 'start'],
        ],
    ],
];
