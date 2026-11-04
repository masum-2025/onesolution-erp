<?php

// Ready-made warning letter.
return [
    'key' => 'warning',
    'kind' => 'warning',
    'code' => 'WRN',
    'name' => ['en' => 'Warning letter', 'bn' => 'সতর্কীকরণ চিঠি'],
    'description' => ['en' => 'A written warning about a named incident, asking for an explanation.', 'bn' => 'নির্দিষ্ট ঘটনার জন্য লিখিত সতর্কীকরণ ও ব্যাখ্যা চাওয়া।'],
    'page' => ['size' => 'A4', 'margin_mm' => 20],
    'letterhead' => ['style' => 'name', 'logo' => true, 'align' => 'center', 'address' => []],
    'inputs' => [
        ['key' => 'incident_date', 'type' => 'date', 'required' => true, 'label' => ['en' => 'Date of the incident', 'bn' => 'ঘটনার তারিখ']],
        ['key' => 'incident', 'type' => 'long_text', 'required' => true, 'label' => ['en' => 'What happened', 'bn' => 'কী ঘটেছে']],
        ['key' => 'reply_by', 'type' => 'date', 'required' => true, 'label' => ['en' => 'Reply by', 'bn' => 'জবাব দেওয়ার শেষ তারিখ']],
    ],
    'bodies' => [
        'en' => [
            ['type' => 'paragraph', 'text' => "Ref: {letter.number}\nDate: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\n{employee.designation}\nEmployee code: {employee.code}"],
            ['type' => 'heading', 'text' => 'Subject: Warning', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'Dear {employee.name},'],
            ['type' => 'paragraph', 'text' => 'It has come to our notice that on {input.incident_date}:', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => '{input.incident}', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'This is against the company\'s rules of conduct. You are asked to explain in writing by **{input.reply_by}**. Please note that a repeat may lead to further action under the service rules.', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'Authorised signatory, {company.name}', 'align' => 'start'],
        ],
        'bn' => [
            ['type' => 'paragraph', 'text' => "সূত্র: {letter.number}\nতারিখ: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\n{employee.designation}\nকর্মী কোড: {employee.code}"],
            ['type' => 'heading', 'text' => 'বিষয়: সতর্কীকরণ', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'প্রিয় {employee.name},'],
            ['type' => 'paragraph', 'text' => 'আমাদের নজরে এসেছে যে {input.incident_date} তারিখে:', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => '{input.incident}', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'এটি প্রতিষ্ঠানের আচরণবিধির পরিপন্থী। **{input.reply_by}** তারিখের মধ্যে লিখিত ব্যাখ্যা দিতে বলা হলো। পুনরাবৃত্তি হলে চাকরি বিধিমালা অনুযায়ী আরও ব্যবস্থা নেওয়া হতে পারে।', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'অনুমোদিত স্বাক্ষরকারী, {company.name}', 'align' => 'start'],
        ],
    ],
];
