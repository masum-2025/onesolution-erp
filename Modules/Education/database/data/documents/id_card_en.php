<?php

/*
| Ready-made design: a student ID card in English (85.6 x 54 mm, portrait),
| for colleges, universities and coaching centres. Added as a draft.
*/

return [
    'key' => 'id_card_en',
    'kind' => 'id_card',
    'name' => ['en' => 'Student ID card (English)', 'bn' => 'শিক্ষার্থী পরিচয়পত্র (ইংরেজি)'],
    'description' => ['en' => 'Upright card with photo, program, batch and QR check.', 'bn' => 'খাড়া কার্ড: ছবি, প্রোগ্রাম, ব্যাচ ও QR যাচাই।'],
    'locale' => 'en',
    'sectors' => ['college', 'university', 'coaching'],
    'page' => ['size' => 'id_card', 'orientation' => 'portrait'],
    'inputs' => [],
    'layout' => [
        'background' => ['color' => '#ffffff', 'asset_id' => null],
        'elements' => [
            ['id' => 'band', 'type' => 'box', 'x' => 0, 'y' => 0, 'w' => 540, 'h' => 160, 'color' => null, 'thickness' => 0, 'fill' => '#0f766e', 'radius' => 0],
            ['id' => 'school', 'type' => 'text', 'x' => 20, 'y' => 30, 'w' => 500, 'h' => 100, 'text' => '{institution.name}', 'size' => 85, 'weight' => 'bold', 'align' => 'center', 'color' => '#ffffff'],
            ['id' => 'photo', 'type' => 'photo', 'x' => 150, 'y' => 190, 'w' => 240, 'h' => 280, 'fit' => 'cover', 'radius' => 20],
            ['id' => 'name', 'type' => 'text', 'x' => 20, 'y' => 490, 'w' => 500, 'h' => 60, 'text' => '{student.name}', 'size' => 95, 'weight' => 'bold', 'align' => 'center', 'color' => '#0f172a'],
            [
                'id' => 'details', 'type' => 'text', 'x' => 30, 'y' => 560, 'w' => 310, 'h' => 200, 'size' => 63, 'line_height' => 145, 'color' => '#1e293b',
                'text' => "ID: {student.code}\n{program}\n{level} {section}\nBatch: {batch}\nValid until: {document.valid_until}",
            ],
            ['id' => 'qr', 'type' => 'qr', 'x' => 350, 'y' => 570, 'w' => 160, 'h' => 160, 'color' => '#0f172a'],
            ['id' => 'footer', 'type' => 'box', 'x' => 0, 'y' => 816, 'w' => 540, 'h' => 40, 'color' => null, 'thickness' => 0, 'fill' => '#0f766e', 'radius' => 0],
        ],
    ],
];
