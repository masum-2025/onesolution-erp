<?php

// Ready-made release (relieving) letter after a resignation.
return [
    'key' => 'relieving',
    'kind' => 'relieving',
    'code' => 'REL',
    'name' => ['en' => 'Release letter', 'bn' => 'অব্যাহতিপত্র'],
    'description' => ['en' => 'Accepts the resignation and releases the employee from their last working day (needs it in HRM).', 'bn' => 'পদত্যাগ গ্রহণ ও শেষ কর্মদিবস থেকে অব্যাহতি (HRM-এ শেষ কর্মদিবস লাগবে)।'],
    'page' => ['size' => 'A4', 'margin_mm' => 20],
    'letterhead' => ['style' => 'name', 'logo' => true, 'align' => 'center', 'address' => []],
    'inputs' => [
        ['key' => 'resignation_date', 'type' => 'date', 'required' => true, 'label' => ['en' => 'Resignation letter dated', 'bn' => 'পদত্যাগপত্রের তারিখ']],
    ],
    'bodies' => [
        'en' => [
            ['type' => 'paragraph', 'text' => "Ref: {letter.number}\nDate: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\n{employee.designation}\nEmployee code: {employee.code}"],
            ['type' => 'heading', 'text' => 'Subject: Release from service', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'Dear {employee.name},'],
            ['type' => 'paragraph', 'text' => 'With reference to your resignation letter dated {input.resignation_date}, we accept your resignation. You are released from your duties at the close of business on **{employee.exits_on}**.', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'Your final settlement will be made as per company policy once handover is complete. We thank you for your service and wish you well.', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'Authorised signatory, {company.name}', 'align' => 'start'],
        ],
        'bn' => [
            ['type' => 'paragraph', 'text' => "সূত্র: {letter.number}\nতারিখ: {letter.date}"],
            ['type' => 'paragraph', 'text' => "**{employee.name}**\n{employee.designation}\nকর্মী কোড: {employee.code}"],
            ['type' => 'heading', 'text' => 'বিষয়: চাকরি থেকে অব্যাহতি', 'level' => 2],
            ['type' => 'paragraph', 'text' => 'প্রিয় {employee.name},'],
            ['type' => 'paragraph', 'text' => 'আপনার {input.resignation_date} তারিখের পদত্যাগপত্রের প্রেক্ষিতে আপনার পদত্যাগ গ্রহণ করা হলো। **{employee.exits_on}** তারিখ অফিস সময় শেষে আপনাকে দায়িত্ব থেকে অব্যাহতি দেওয়া হলো।', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'দায়িত্ব হস্তান্তর শেষ হলে প্রতিষ্ঠানের নীতি অনুযায়ী আপনার চূড়ান্ত পাওনা পরিশোধ করা হবে। আপনার সেবার জন্য ধন্যবাদ ও শুভকামনা।', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'অনুমোদিত স্বাক্ষরকারী, {company.name}', 'align' => 'start'],
        ],
    ],
];
