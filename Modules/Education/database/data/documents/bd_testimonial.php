<?php

/*
| Ready-made design: a testimonial (প্রশংসাপত্র) in Bangla, A4 portrait, with
| the student's conduct asked when issuing and the QR check. Added as a draft.
*/

return [
    'key' => 'bd_testimonial',
    'kind' => 'certificate',
    'name' => ['en' => 'Testimonial (Bangla)', 'bn' => 'প্রশংসাপত্র'],
    'description' => ['en' => 'A4 testimonial: class, roll, session, conduct and QR check.', 'bn' => 'A4 প্রশংসাপত্র: শ্রেণি, রোল, সেশন, আচরণ ও QR যাচাই।'],
    'locale' => 'bn',
    'sectors' => ['school', 'college', 'madrasa'],
    'page' => ['size' => 'a4', 'orientation' => 'portrait'],
    'inputs' => [
        ['key' => 'conduct', 'label' => ['en' => 'Conduct', 'bn' => 'চরিত্র ও আচরণ'], 'required' => true, 'multiline' => false],
    ],
    'layout' => [
        'background' => ['color' => '#ffffff', 'asset_id' => null],
        'elements' => [
            ['id' => 'frame', 'type' => 'box', 'x' => 100, 'y' => 100, 'w' => 1900, 'h' => 2770, 'color' => '#1e3a8a', 'thickness' => 8, 'fill' => null, 'radius' => 20],
            ['id' => 'school', 'type' => 'text', 'x' => 200, 'y' => 220, 'w' => 1700, 'h' => 120, 'text' => '{institution.name}', 'size' => 220, 'weight' => 'bold', 'align' => 'center', 'color' => '#1e3a8a'],
            ['id' => 'rule', 'type' => 'line', 'x' => 200, 'y' => 380, 'w' => 1700, 'h' => 0, 'color' => '#1e3a8a', 'thickness' => 4],
            ['id' => 'title', 'type' => 'text', 'x' => 200, 'y' => 460, 'w' => 1700, 'h' => 120, 'text' => 'প্রশংসাপত্র', 'size' => 200, 'weight' => 'bold', 'align' => 'center', 'color' => '#0f172a'],
            ['id' => 'number', 'type' => 'text', 'x' => 220, 'y' => 660, 'w' => 800, 'h' => 60, 'text' => 'ক্রমিক নং: {document.number}', 'size' => 110, 'color' => '#334155'],
            ['id' => 'date', 'type' => 'text', 'x' => 1080, 'y' => 660, 'w' => 800, 'h' => 60, 'text' => 'তারিখ: {document.date}', 'size' => 110, 'align' => 'end', 'color' => '#334155'],
            [
                'id' => 'body', 'type' => 'text', 'x' => 220, 'y' => 840, 'w' => 1660, 'h' => 1100, 'size' => 130, 'line_height' => 190, 'align' => 'justify', 'color' => '#0f172a',
                'text' => "এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, {student.name}, পিতা: {guardian.father}, মাতা: {guardian.mother}, অত্র প্রতিষ্ঠানের {level} শ্রেণির একজন নিয়মিত শিক্ষার্থী ছিল। তার শিক্ষার্থী আইডি {student.code}, রোল {roll}, সেশন {session}।\n\nআমার জানামতে সে রাষ্ট্র বা সমাজবিরোধী কোনো কাজে জড়িত ছিল না। তার চরিত্র ও আচরণ {input.conduct}।\n\nআমি তার জীবনের সর্বাঙ্গীণ সাফল্য কামনা করি।",
            ],
            ['id' => 'qr', 'type' => 'qr', 'x' => 220, 'y' => 2360, 'w' => 280, 'h' => 280, 'color' => '#0f172a'],
            ['id' => 'qr_text', 'type' => 'text', 'x' => 220, 'y' => 2650, 'w' => 500, 'h' => 50, 'text' => 'যাচাই করতে QR স্ক্যান করুন', 'size' => 80, 'color' => '#64748b'],
            ['id' => 'sign', 'type' => 'line', 'x' => 1300, 'y' => 2550, 'w' => 580, 'h' => 0, 'color' => '#334155', 'thickness' => 3],
            ['id' => 'sign_text', 'type' => 'text', 'x' => 1300, 'y' => 2570, 'w' => 580, 'h' => 60, 'text' => 'প্রধান শিক্ষক', 'size' => 110, 'align' => 'center', 'color' => '#334155'],
        ],
    ],
];
