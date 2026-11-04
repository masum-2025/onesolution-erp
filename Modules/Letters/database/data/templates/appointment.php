<?php

// Ready-made appointment letter (a company copies it as a draft and adapts it).
return [
    'key' => 'appointment',
    'kind' => 'appointment',
    'code' => 'APT',
    'name' => ['en' => 'Appointment letter', 'bn' => 'নিয়োগপত্র'],
    'description' => ['en' => 'Offers the post, joining date, salary with its breakdown and the main terms.', 'bn' => 'পদ, যোগদানের তারিখ, বেতন ও তার বিবরণ এবং প্রধান শর্তসহ নিয়োগ।'],
    'page' => ['size' => 'A4', 'margin_mm' => 20],
    'letterhead' => ['style' => 'name', 'logo' => true, 'align' => 'center', 'address' => []],
    'inputs' => [
        ['key' => 'terms', 'type' => 'long_text', 'required' => false, 'label' => ['en' => 'Other terms (optional)', 'bn' => 'অন্যান্য শর্ত (ঐচ্ছিক)']],
    ],
    'bodies' => [
        'en' => [
            ['type' => 'paragraph', 'text' => "Ref: {letter.number}\nDate: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\nEmployee code: {employee.code}"],
            ['type' => 'heading', 'text' => 'Letter of appointment', 'level' => 1, 'align' => 'center'],
            ['type' => 'paragraph', 'text' => 'Dear {employee.name},'],
            ['type' => 'paragraph', 'text' => 'We are pleased to appoint you as **{employee.designation}** at {company.name}, on the terms below. Your appointment takes effect from **{employee.joined_on}**.', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'Your monthly gross salary will be **{pay.gross}**, made up as follows:'],
            ['type' => 'table', 'field' => 'pay.breakdown'],
            ['type' => 'list', 'ordered' => true, 'items' => [
                'You will be on probation for the period set by company policy; on satisfactory completion your appointment will be confirmed in writing.',
                'Your working hours, leave and other benefits follow the company\'s service rules as amended from time to time.',
                'Either side may end this employment by giving notice as set out in the service rules and the law.',
                'You will keep the company\'s information confidential during and after your employment.',
            ]],
            ['type' => 'paragraph', 'text' => '{input.terms}'],
            ['type' => 'paragraph', 'text' => 'Please sign and return a copy of this letter as your acceptance. We look forward to working with you.', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'Authorised signatory, {company.name}', 'align' => 'start'],
        ],
        'bn' => [
            ['type' => 'paragraph', 'text' => "সূত্র: {letter.number}\nতারিখ: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\nকর্মী কোড: {employee.code}"],
            ['type' => 'heading', 'text' => 'নিয়োগপত্র', 'level' => 1, 'align' => 'center'],
            ['type' => 'paragraph', 'text' => 'প্রিয় {employee.name},'],
            ['type' => 'paragraph', 'text' => 'আমরা আনন্দের সাথে জানাচ্ছি যে নিচের শর্তে আপনাকে {company.name}-এ **{employee.designation}** পদে নিয়োগ দেওয়া হলো। আপনার নিয়োগ **{employee.joined_on}** থেকে কার্যকর।', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'আপনার মাসিক মোট বেতন **{pay.gross}**, যার বিবরণ নিচে:'],
            ['type' => 'table', 'field' => 'pay.breakdown'],
            ['type' => 'list', 'ordered' => true, 'items' => [
                'প্রতিষ্ঠানের নীতি অনুযায়ী নির্ধারিত সময় আপনি শিক্ষানবিশ থাকবেন; সন্তোষজনকভাবে শেষ হলে লিখিতভাবে আপনার চাকরি স্থায়ী করা হবে।',
                'আপনার কর্মঘণ্টা, ছুটি ও অন্যান্য সুবিধা প্রতিষ্ঠানের চাকরি বিধিমালা (সময়ে সময়ে সংশোধিত) অনুযায়ী হবে।',
                'চাকরি বিধিমালা ও আইন অনুযায়ী নোটিশ দিয়ে যেকোনো পক্ষ এই চাকরি শেষ করতে পারবে।',
                'চাকরিকালে ও পরে আপনি প্রতিষ্ঠানের তথ্য গোপন রাখবেন।',
            ]],
            ['type' => 'paragraph', 'text' => '{input.terms}'],
            ['type' => 'paragraph', 'text' => 'সম্মতির প্রমাণ হিসেবে এই চিঠির একটি কপিতে স্বাক্ষর করে ফেরত দিন। আপনার সাথে কাজ করার অপেক্ষায় রইলাম।', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'অনুমোদিত স্বাক্ষরকারী, {company.name}', 'align' => 'start'],
        ],
    ],
];
