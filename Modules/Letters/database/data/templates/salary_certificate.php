<?php

// Ready-made salary certificate (for a bank, embassy or landlord).
return [
    'key' => 'salary_certificate',
    'kind' => 'salary_certificate',
    'code' => 'SAL',
    'name' => ['en' => 'Salary certificate', 'bn' => 'বেতন সনদ'],
    'description' => ['en' => 'Certifies employment and the present salary with its breakdown.', 'bn' => 'চাকরি ও বর্তমান বেতন (বিবরণসহ) প্রত্যয়ন।'],
    'page' => ['size' => 'A4', 'margin_mm' => 20],
    'letterhead' => ['style' => 'name', 'logo' => true, 'align' => 'center', 'address' => []],
    'inputs' => [
        ['key' => 'purpose', 'type' => 'text', 'required' => true, 'label' => ['en' => 'Asked for (e.g. a bank loan)', 'bn' => 'যে কাজে (যেমন ব্যাংক ঋণ)']],
    ],
    'bodies' => [
        'en' => [
            ['type' => 'paragraph', 'text' => "Ref: {letter.number}\nDate: {letter.date}"],
            ['type' => 'spacer', 'size' => 'md'],
            ['type' => 'heading', 'text' => 'SALARY CERTIFICATE', 'level' => 1, 'align' => 'center'],
            ['type' => 'paragraph', 'text' => 'This is to certify that **{employee.name}** (employee code {employee.code}) has been working at {company.name} as **{employee.designation}** since {employee.joined_on}. The present monthly salary is as below:', 'align' => 'justify'],
            ['type' => 'table', 'field' => 'pay.breakdown'],
            ['type' => 'paragraph', 'text' => 'This certificate is issued on request, for {input.purpose}, without any responsibility on the part of the company.', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'Authorised signatory, {company.name}', 'align' => 'start'],
        ],
        'bn' => [
            ['type' => 'paragraph', 'text' => "সূত্র: {letter.number}\nতারিখ: {letter.date}"],
            ['type' => 'spacer', 'size' => 'md'],
            ['type' => 'heading', 'text' => 'বেতন সনদ', 'level' => 1, 'align' => 'center'],
            ['type' => 'paragraph', 'text' => 'এই মর্মে প্রত্যয়ন করা যাচ্ছে যে **{employee.name}** (কর্মী কোড {employee.code}) {employee.joined_on} থেকে {company.name}-এ **{employee.designation}** পদে কর্মরত আছেন। তাঁর বর্তমান মাসিক বেতন নিচে:', 'align' => 'justify'],
            ['type' => 'table', 'field' => 'pay.breakdown'],
            ['type' => 'paragraph', 'text' => 'তাঁর অনুরোধে {input.purpose}-এর জন্য এই সনদ দেওয়া হলো; এর জন্য প্রতিষ্ঠান কোনো দায় বহন করবে না।', 'align' => 'justify'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'signature', 'name' => '', 'title' => 'অনুমোদিত স্বাক্ষরকারী, {company.name}', 'align' => 'start'],
        ],
    ],
];
