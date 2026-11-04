<?php

// Ready-made no-objection letter (travel abroad, higher study, a visa).
return [
    'key' => 'noc',
    'kind' => 'noc',
    'code' => 'NOC',
    'name' => ['en' => 'No objection letter', 'bn' => 'অনাপত্তি পত্র'],
    'description' => ['en' => 'States the company has no objection to a purpose (travel, study, a visa).', 'bn' => 'কোনো কাজে (ভ্রমণ, পড়াশোনা, ভিসা) প্রতিষ্ঠানের আপত্তি নেই এই মর্মে।'],
    'page' => ['size' => 'A4', 'margin_mm' => 20],
    'letterhead' => ['style' => 'name', 'logo' => true, 'align' => 'center', 'address' => []],
    'inputs' => [
        ['key' => 'purpose', 'type' => 'long_text', 'required' => true, 'label' => ['en' => 'No objection to', 'bn' => 'যে বিষয়ে অনাপত্তি']],
    ],
    'bodies' => [
        'en' => [
            ['type' => 'paragraph', 'text' => "Ref: {letter.number}\nDate: {letter.date}"],
            ['type' => 'spacer', 'size' => 'md'],
            ['type' => 'heading', 'text' => 'NO OBJECTION CERTIFICATE', 'level' => 1, 'align' => 'center'],
            ['type' => 'paragraph', 'text' => 'This is to certify that **{employee.name}** (employee code {employee.code}) has been working at {company.name} as **{employee.designation}** since {employee.joined_on}.', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'The company has no objection to: {input.purpose}', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'The employee will resume duties at the end of the approved leave.', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'Authorised signatory, {company.name}', 'align' => 'start'],
        ],
        'bn' => [
            ['type' => 'paragraph', 'text' => "সূত্র: {letter.number}\nতারিখ: {letter.date}"],
            ['type' => 'spacer', 'size' => 'md'],
            ['type' => 'heading', 'text' => 'অনাপত্তি সনদ', 'level' => 1, 'align' => 'center'],
            ['type' => 'paragraph', 'text' => 'এই মর্মে প্রত্যয়ন করা যাচ্ছে যে **{employee.name}** (কর্মী কোড {employee.code}) {employee.joined_on} থেকে {company.name}-এ **{employee.designation}** পদে কর্মরত আছেন।', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'নিচের বিষয়ে প্রতিষ্ঠানের কোনো আপত্তি নেই: {input.purpose}', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'অনুমোদিত ছুটি শেষে তিনি কাজে যোগ দেবেন।', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'অনুমোদিত স্বাক্ষরকারী, {company.name}', 'align' => 'start'],
        ],
    ],
];
