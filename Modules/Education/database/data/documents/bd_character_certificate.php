<?php

/*
| Ready-made design: a character certificate (চারিত্রিক সনদপত্র) in Bangla,
| A5 landscape, with the student's conduct asked when issuing and the QR
| check. Added as a draft.
*/

return [
    'key' => 'bd_character_certificate',
    'kind' => 'certificate',
    'name' => ['en' => 'Character certificate (Bangla)', 'bn' => 'চারিত্রিক সনদপত্র'],
    'description' => ['en' => 'A5 character certificate: conduct and QR check.', 'bn' => 'A5 চারিত্রিক সনদপত্র: আচরণ ও QR যাচাই।'],
    'locale' => 'bn',
    'sectors' => ['school', 'college', 'madrasa', 'university'],
    'page' => ['size' => 'a5', 'orientation' => 'landscape'],
    'inputs' => [
        ['key' => 'conduct', 'label' => ['en' => 'Conduct', 'bn' => 'চরিত্র ও আচরণ'], 'required' => true, 'multiline' => false],
    ],
    'layout' => [
        'background' => ['color' => '#fffdf7', 'asset_id' => null],
        'elements' => [
            ['id' => 'frame', 'type' => 'box', 'x' => 60, 'y' => 60, 'w' => 1980, 'h' => 1360, 'color' => '#a16207', 'thickness' => 8, 'fill' => null, 'radius' => 20],
            ['id' => 'school', 'type' => 'text', 'x' => 150, 'y' => 130, 'w' => 1800, 'h' => 100, 'text' => '{institution.name}', 'size' => 180, 'weight' => 'bold', 'align' => 'center', 'color' => '#713f12'],
            ['id' => 'title', 'type' => 'text', 'x' => 150, 'y' => 270, 'w' => 1800, 'h' => 90, 'text' => 'চারিত্রিক সনদপত্র', 'size' => 150, 'weight' => 'bold', 'align' => 'center', 'color' => '#0f172a'],
            ['id' => 'meta', 'type' => 'text', 'x' => 150, 'y' => 400, 'w' => 1800, 'h' => 60, 'text' => 'ক্রমিক নং: {document.number}     তারিখ: {document.date}', 'size' => 100, 'align' => 'center', 'color' => '#334155'],
            [
                'id' => 'body', 'type' => 'text', 'x' => 180, 'y' => 520, 'w' => 1740, 'h' => 500, 'size' => 120, 'line_height' => 180, 'align' => 'justify', 'color' => '#0f172a',
                'text' => "এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, {student.name}, পিতা: {guardian.father}, অত্র প্রতিষ্ঠানের {level} শ্রেণির শিক্ষার্থী (আইডি {student.code})। আমার জানামতে তার চরিত্র ও আচরণ {input.conduct}। আমি তার উত্তরোত্তর সাফল্য কামনা করি।",
            ],
            ['id' => 'qr', 'type' => 'qr', 'x' => 180, 'y' => 1080, 'w' => 240, 'h' => 240, 'color' => '#0f172a'],
            ['id' => 'sign', 'type' => 'line', 'x' => 1400, 'y' => 1250, 'w' => 520, 'h' => 0, 'color' => '#334155', 'thickness' => 3],
            ['id' => 'sign_text', 'type' => 'text', 'x' => 1400, 'y' => 1270, 'w' => 520, 'h' => 60, 'text' => 'প্রধান শিক্ষক', 'size' => 100, 'align' => 'center', 'color' => '#334155'],
        ],
    ],
];
