<?php

// Ready-made experience certificate (for someone whose last working day HRM knows).
return [
    'key' => 'experience',
    'kind' => 'experience',
    'code' => 'EXP',
    'name' => ['en' => 'Experience certificate', 'bn' => 'অভিজ্ঞতা সনদ'],
    'description' => ['en' => 'Certifies the period of service and the designation (needs the last working day in HRM).', 'bn' => 'চাকরির মেয়াদ ও পদের সনদ (HRM-এ শেষ কর্মদিবস লাগবে)।'],
    'page' => ['size' => 'A4', 'margin_mm' => 20],
    'letterhead' => ['style' => 'name', 'logo' => true, 'align' => 'center', 'address' => []],
    'inputs' => [],
    'bodies' => [
        'en' => [
            ['type' => 'paragraph', 'text' => "Ref: {letter.number}\nDate: {letter.date}"],
            ['type' => 'spacer', 'size' => 'md'],
            ['type' => 'heading', 'text' => 'TO WHOM IT MAY CONCERN', 'level' => 1, 'align' => 'center'],
            ['type' => 'paragraph', 'text' => 'This is to certify that **{employee.name}** (employee code {employee.code}) worked at {company.name} from **{employee.joined_on}** to **{employee.exits_on}** ({employee.service_length}). At the time of leaving, the designation was **{employee.designation}**.', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'During this period the conduct and work were found satisfactory. We wish every success in the future.', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'Authorised signatory, {company.name}', 'align' => 'start'],
        ],
        'bn' => [
            ['type' => 'paragraph', 'text' => "সূত্র: {letter.number}\nতারিখ: {letter.date}"],
            ['type' => 'spacer', 'size' => 'md'],
            ['type' => 'heading', 'text' => 'যাঁর জন্য প্রযোজ্য', 'level' => 1, 'align' => 'center'],
            ['type' => 'paragraph', 'text' => 'এই মর্মে প্রত্যয়ন করা যাচ্ছে যে **{employee.name}** (কর্মী কোড {employee.code}) **{employee.joined_on}** থেকে **{employee.exits_on}** পর্যন্ত ({employee.service_length}) {company.name}-এ কর্মরত ছিলেন। চাকরি ছাড়ার সময় তাঁর পদ ছিল **{employee.designation}**।', 'align' => 'justify'],
            ['type' => 'paragraph', 'text' => 'এই সময়ে তাঁর আচরণ ও কাজ সন্তোষজনক ছিল। তাঁর ভবিষ্যৎ সাফল্য কামনা করি।', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'অনুমোদিত স্বাক্ষরকারী, {company.name}', 'align' => 'start'],
        ],
    ],
];
