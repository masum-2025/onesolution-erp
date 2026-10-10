<?php

/*
| Ready-made design: a student ID card in Bangla (85.6 x 54 mm, landscape),
| with the photo, class, roll, guardian's phone and the QR check. Added as a
| draft; the institution adds its logo and colours, then makes it active.
*/

return [
    'key' => 'bd_id_card',
    'kind' => 'id_card',
    'name' => ['en' => 'Student ID card (Bangla)', 'bn' => 'শিক্ষার্থী পরিচয়পত্র'],
    'description' => ['en' => 'Card size, photo, class, roll, guardian and QR check.', 'bn' => 'কার্ড মাপ, ছবি, শ্রেণি, রোল, অভিভাবক ও QR যাচাই।'],
    'locale' => 'bn',
    'sectors' => ['school', 'college', 'madrasa'],
    'page' => ['size' => 'id_card', 'orientation' => 'landscape'],
    'inputs' => [],
    'layout' => [
        'background' => ['color' => '#ffffff', 'asset_id' => null],
        'elements' => [
            ['id' => 'band', 'type' => 'box', 'x' => 0, 'y' => 0, 'w' => 856, 'h' => 110, 'color' => null, 'thickness' => 0, 'fill' => '#1e3a8a', 'radius' => 0],
            ['id' => 'school', 'type' => 'text', 'x' => 30, 'y' => 15, 'w' => 796, 'h' => 50, 'text' => '{institution.name}', 'size' => 90, 'weight' => 'bold', 'align' => 'center', 'color' => '#ffffff'],
            ['id' => 'title', 'type' => 'text', 'x' => 30, 'y' => 63, 'w' => 796, 'h' => 40, 'text' => 'শিক্ষার্থী পরিচয়পত্র', 'size' => 65, 'align' => 'center', 'color' => '#dbeafe'],
            ['id' => 'photo', 'type' => 'photo', 'x' => 35, 'y' => 140, 'w' => 200, 'h' => 240, 'fit' => 'cover', 'radius' => 10],
            ['id' => 'name', 'type' => 'text', 'x' => 260, 'y' => 135, 'w' => 560, 'h' => 50, 'text' => '{student.name}', 'size' => 90, 'weight' => 'bold', 'color' => '#0f172a'],
            [
                'id' => 'details', 'type' => 'text', 'x' => 260, 'y' => 190, 'w' => 380, 'h' => 220, 'size' => 65, 'line_height' => 145, 'color' => '#1e293b',
                'text' => "শ্রেণি: {level}  শাখা: {section}\nরোল: {roll}\nআইডি: {student.code}\nঅভিভাবক: {guardian.primary}\nফোন: {guardian.primary_phone}",
            ],
            ['id' => 'qr', 'type' => 'qr', 'x' => 660, 'y' => 240, 'w' => 160, 'h' => 160, 'color' => '#0f172a'],
            ['id' => 'valid', 'type' => 'text', 'x' => 35, 'y' => 400, 'w' => 400, 'h' => 40, 'text' => 'মেয়াদ: {document.valid_until}', 'size' => 60, 'color' => '#475569'],
            ['id' => 'sign', 'type' => 'line', 'x' => 520, 'y' => 455, 'w' => 300, 'h' => 0, 'color' => '#64748b', 'thickness' => 2],
            ['id' => 'sign_text', 'type' => 'text', 'x' => 520, 'y' => 460, 'w' => 300, 'h' => 35, 'text' => 'প্রধান শিক্ষক', 'size' => 55, 'align' => 'center', 'color' => '#475569'],
            ['id' => 'footer', 'type' => 'box', 'x' => 0, 'y' => 510, 'w' => 856, 'h' => 30, 'color' => null, 'thickness' => 0, 'fill' => '#1e3a8a', 'radius' => 0],
        ],
    ],
];
