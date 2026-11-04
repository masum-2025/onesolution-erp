<?php

// Ready-made increment letter, issued from an applied increment round (its basic before and after, rate, raise, day; the pay from that day).
return [
    'key' => 'increment',
    'kind' => 'increment',
    'code' => 'INC',
    'name' => ['en' => 'Salary increment letter', 'bn' => 'বেতন বৃদ্ধির চিঠি'],
    'description' => ['en' => 'The basic before and after, the rate and the new salary\'s breakdown; issued from an applied increment round.', 'bn' => 'আগের ও নতুন মূল বেতন, হার আর নতুন বেতনের বিবরণ; কার্যকর হওয়া বেতন বৃদ্ধির round থেকে দেওয়া হয়।'],
    'page' => ['size' => 'A4', 'margin_mm' => 20],
    'letterhead' => ['style' => 'name', 'logo' => true, 'align' => 'center', 'address' => []],
    'inputs' => [],
    'bodies' => [
        'en' => [
            ['type' => 'paragraph', 'text' => "Ref: {letter.number}\nDate: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\n{employee.designation}\nEmployee code: {employee.code}"],
            ['type' => 'heading', 'text' => 'Subject: Salary increment', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'Dear {employee.name},'],
            ['type' => 'paragraph', 'text' => 'In recognition of your performance, we are pleased to raise your basic salary from {increment.previous_basic} to **{increment.new_basic}** ({increment.rate}, a raise of {increment.raise}) with effect from **{increment.effective_from}**. Your new monthly gross salary is **{pay.gross}**, as below:', 'align' => 'justify'],
            ['type' => 'table', 'field' => 'pay.breakdown'],
            ['type' => 'paragraph', 'text' => 'All other terms of your employment remain the same. We thank you for your contribution and look forward to your continued success.', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'Authorised signatory, {company.name}', 'align' => 'start'],
        ],
        'bn' => [
            ['type' => 'paragraph', 'text' => "সূত্র: {letter.number}\nতারিখ: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\n{employee.designation}\nকর্মী কোড: {employee.code}"],
            ['type' => 'heading', 'text' => 'বিষয়: বেতন বৃদ্ধি', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'প্রিয় {employee.name},'],
            ['type' => 'paragraph', 'text' => 'আপনার কাজের স্বীকৃতি হিসেবে **{increment.effective_from}** থেকে আপনার মূল বেতন {increment.previous_basic} থেকে বাড়িয়ে **{increment.new_basic}** করা হলো ({increment.rate}, অর্থাৎ {increment.raise} বৃদ্ধি)। আপনার নতুন মাসিক মোট বেতন **{pay.gross}**, বিবরণ নিচে:', 'align' => 'justify'],
            ['type' => 'table', 'field' => 'pay.breakdown'],
            ['type' => 'paragraph', 'text' => 'চাকরির অন্যান্য সব শর্ত অপরিবর্তিত থাকবে। আপনার অবদানের জন্য ধন্যবাদ এবং আগামীতেও সাফল্য কামনা করি।', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'অনুমোদিত স্বাক্ষরকারী, {company.name}', 'align' => 'start'],
        ],
    ],
];
