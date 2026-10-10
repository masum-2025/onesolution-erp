<?php

/*
| Ready-made design: a transfer certificate (ছাড়পত্র) in Bangla, A4
| portrait: why the student leaves and their dues, asked when issuing, and
| the QR check. Added as a draft.
*/

return [
    'key' => 'bd_transfer_certificate',
    'kind' => 'certificate',
    'name' => ['en' => 'Transfer certificate (Bangla)', 'bn' => 'ছাড়পত্র'],
    'description' => ['en' => 'A4 transfer certificate: last class, reason, dues and QR check.', 'bn' => 'A4 ছাড়পত্র: সর্বশেষ শ্রেণি, কারণ, পাওনা ও QR যাচাই।'],
    'locale' => 'bn',
    'sectors' => ['school', 'college', 'madrasa'],
    'page' => ['size' => 'a4', 'orientation' => 'portrait'],
    'inputs' => [
        ['key' => 'reason', 'label' => ['en' => 'Why the student leaves', 'bn' => 'প্রতিষ্ঠান ছাড়ার কারণ'], 'required' => true, 'multiline' => false],
        ['key' => 'dues', 'label' => ['en' => 'Dues', 'bn' => 'পাওনা'], 'required' => false, 'multiline' => false],
    ],
    'layout' => [
        'background' => ['color' => '#ffffff', 'asset_id' => null],
        'elements' => [
            ['id' => 'frame', 'type' => 'box', 'x' => 100, 'y' => 100, 'w' => 1900, 'h' => 2770, 'color' => '#7c2d12', 'thickness' => 8, 'fill' => null, 'radius' => 20],
            ['id' => 'school', 'type' => 'text', 'x' => 200, 'y' => 220, 'w' => 1700, 'h' => 120, 'text' => '{institution.name}', 'size' => 220, 'weight' => 'bold', 'align' => 'center', 'color' => '#7c2d12'],
            ['id' => 'rule', 'type' => 'line', 'x' => 200, 'y' => 380, 'w' => 1700, 'h' => 0, 'color' => '#7c2d12', 'thickness' => 4],
            ['id' => 'title', 'type' => 'text', 'x' => 200, 'y' => 460, 'w' => 1700, 'h' => 120, 'text' => 'ছাড়পত্র', 'size' => 200, 'weight' => 'bold', 'align' => 'center', 'color' => '#0f172a'],
            ['id' => 'number', 'type' => 'text', 'x' => 220, 'y' => 660, 'w' => 800, 'h' => 60, 'text' => 'ক্রমিক নং: {document.number}', 'size' => 110, 'color' => '#334155'],
            ['id' => 'date', 'type' => 'text', 'x' => 1080, 'y' => 660, 'w' => 800, 'h' => 60, 'text' => 'তারিখ: {document.date}', 'size' => 110, 'align' => 'end', 'color' => '#334155'],
            [
                'id' => 'body', 'type' => 'text', 'x' => 220, 'y' => 840, 'w' => 1660, 'h' => 1200, 'size' => 130, 'line_height' => 190, 'align' => 'justify', 'color' => '#0f172a',
                'text' => "এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, {student.name}, পিতা: {guardian.father}, মাতা: {guardian.mother}, শিক্ষার্থী আইডি {student.code}, {student.admitted_on} তারিখে অত্র প্রতিষ্ঠানে ভর্তি হয়ে {level} শ্রেণি পর্যন্ত অধ্যয়ন করেছে। তার সর্বশেষ রোল {roll}, সেশন {session}।\n\nপ্রতিষ্ঠান ছাড়ার কারণ: {input.reason}।\nপ্রতিষ্ঠানের পাওনা: {input.dues}\n\nতার ভবিষ্যৎ জীবনের সাফল্য কামনা করি।",
            ],
            ['id' => 'qr', 'type' => 'qr', 'x' => 220, 'y' => 2360, 'w' => 280, 'h' => 280, 'color' => '#0f172a'],
            ['id' => 'qr_text', 'type' => 'text', 'x' => 220, 'y' => 2650, 'w' => 500, 'h' => 50, 'text' => 'যাচাই করতে QR স্ক্যান করুন', 'size' => 80, 'color' => '#64748b'],
            ['id' => 'sign', 'type' => 'line', 'x' => 1300, 'y' => 2550, 'w' => 580, 'h' => 0, 'color' => '#334155', 'thickness' => 3],
            ['id' => 'sign_text', 'type' => 'text', 'x' => 1300, 'y' => 2570, 'w' => 580, 'h' => 60, 'text' => 'প্রধান শিক্ষক', 'size' => 110, 'align' => 'center', 'color' => '#334155'],
        ],
    ],
];
