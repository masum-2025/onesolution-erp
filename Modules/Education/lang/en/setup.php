<?php

// The setup checklist on the education overview (steps Education owns).
return [
    'steps' => [
        'structure' => [
            'label' => 'Programs, classes and subjects',
            'no_programs' => 'No program yet. Pick a ready-made preset for your kind of institution.',
            'no_subjects' => 'No subjects yet. Add the subjects you teach.',
        ],
        'session' => [
            'label' => 'Academic year and session',
            'missing' => 'No session is open. Make this year and its session (or semesters).',
        ],
        'curriculum' => [
            'label' => 'Curriculum (subjects of each class)',
            'missing' => ':count programs have no active curriculum. Say which subjects each class takes.',
        ],
        'sections' => [
            'label' => 'Sections this session',
            'missing' => 'No section in the open session. Make the sections (or batches) of each class.',
        ],
        'class_teachers' => [
            'label' => 'Class teachers',
            'missing' => ':count sections have no class teacher. Teachers enter marks and see their section through it.',
        ],
        'students' => [
            'label' => 'Students admitted',
            'missing' => 'No student studies in the open session yet. Admit students or import them from a spreadsheet.',
        ],
    ],
];
