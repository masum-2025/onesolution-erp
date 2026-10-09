<?php

// Education rule labels.
return [
    'student_code_format' => [
        'label' => 'Student ID format',
        'description' => '{YYYY} or {YY}: year admitted; {PROGRAM}: program code; {SEQ:4}: a number of 4 digits, counted per year (needed). Example: {PROGRAM}-{YY}-{SEQ:3} gives SEC-26-001.',
    ],
    'number_prefixes' => [
        'label' => 'Application and promotion number prefixes',
        'description' => 'Before the year and number, e.g. ADM-2026-0007.',
    ],
    'section_capacity_default' => [
        'label' => 'Students per section (unless set)',
        'description' => 'A new section takes this many students unless it is given its own capacity.',
    ],
    'roll_number_mode' => [
        'label' => 'Roll numbers',
        'description' => 'manual: given by hand; name: in order of name; admission_order: in order of admission (new students get the next roll).',
    ],
    'promotion_approval' => [
        'label' => 'Promotions need a second person',
        'description' => 'A promotion list is applied only after someone else approves it.',
    ],
    'promotion_undo_days' => [
        'label' => 'Days a promotion can be undone',
        'description' => 'A whole promotion can be undone within these days, before the next session starts.',
    ],
    'max_repeats' => [
        'label' => 'Times a student may repeat a level',
        'description' => 'Repeating more often than this needs a confirmation with a reason.',
    ],
    'teacher_scope' => [
        'label' => 'Teachers see',
        'description' => 'own_sections: only the students of the sections they are class teacher of; all: every student of the campus.',
    ],
    'crm_admission_pipelines' => [
        'label' => 'Customer pipelines that are admissions',
        'description' => 'Deals won in these pipelines (by key) become applications here, for example ["admissions"].',
    ],
    'categories' => [
        'admissions' => 'Admissions',
        'numbering' => 'Numbers',
        'sections' => 'Sections and rolls',
        'promotion' => 'Promotion',
        'access' => 'Who sees what',
    ],
];
