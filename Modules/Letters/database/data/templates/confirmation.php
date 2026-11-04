<?php

// Ready-made confirmation letter (end of probation).
return [
    'key' => 'confirmation',
    'kind' => 'confirmation',
    'code' => 'CNF',
    'name' => ['en' => 'Confirmation letter', 'bn' => 'স্থায়ীকরণ পত্র'],
    'description' => ['en' => 'Confirms the employee in the post after probation.', 'bn' => 'শিক্ষানবিশকাল শেষে পদে স্থায়ী করার চিঠি।'],
    'page' => ['size' => 'A4', 'margin_mm' => 20],
    'letterhead' => ['style' => 'name', 'logo' => true, 'align' => 'center', 'address' => []],
    'inputs' => [
        ['key' => 'effective_date', 'type' => 'date', 'required' => true, 'label' => ['en' => 'Confirmed from', 'bn' => 'যে তারিখ থেকে স্থায়ী']],
    ],
    'bodies' => [
        'en' => [
            ['type' => 'paragraph', 'text' => "Ref: {letter.number}\nDate: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\n{employee.designation}\nEmployee code: {employee.code}"],
            ['type' => 'heading', 'text' => 'Subject: Confirmation of employment', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'Dear {employee.name},'],
            ['type' => 'paragraph', 'text' => 'We are pleased to tell you that you have completed your probation satisfactorily. Your employment as **{employee.designation}** is confirmed with effect from **{input.effective_date}**.', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'All other terms of your appointment remain the same. We thank you for your work and wish you every success.', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'Authorised signatory, {company.name}', 'align' => 'start'],
        ],
        'bn' => [
            ['type' => 'paragraph', 'text' => "সূত্র: {letter.number}\nতারিখ: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\n{employee.designation}\nকর্মী কোড: {employee.code}"],
            ['type' => 'heading', 'text' => 'বিষয়: চাকরি স্থায়ীকরণ', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'প্রিয় {employee.name},'],
            ['type' => 'paragraph', 'text' => 'আনন্দের সাথে জানাচ্ছি যে আপনি সন্তোষজনকভাবে শিক্ষানবিশকাল শেষ করেছেন। **{input.effective_date}** থেকে **{employee.designation}** পদে আপনার চাকরি স্থায়ী করা হলো।', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'নিয়োগের অন্যান্য সব শর্ত অপরিবর্তিত থাকবে। আপনার কাজের জন্য ধন্যবাদ এবং সাফল্য কামনা করি।', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'অনুমোদিত স্বাক্ষরকারী, {company.name}', 'align' => 'start'],
        ],
    ],
];
