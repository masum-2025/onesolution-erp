<?php

// Education module names and menu.
return [
    'name' => 'Education',
    'description' => 'Schools, colleges, universities, madrasas and coaching centres: programs, sessions, students, guardians and admissions.',
    'menu' => 'Education',
    'menu_overview' => 'Overview',
    'menu_students' => 'Students',
    'menu_admissions' => 'Admissions',
    'menu_sections' => 'Sections',
    'menu_promotions' => 'Promotions',
    'menu_structure' => 'Programs, classes and sessions',
    'menu_fields' => 'Own fields',
    'menu_import' => 'Import students',
    'menu_documents' => 'ID cards and certificates',
    'menu_designs' => 'Document designs',
    'new_admission' => 'New admission',
    'new_student' => 'New student',
    // Menu words of each kind of institution (manifest "sector_labels").
    'sector' => [
        'school' => ['menu_top' => 'Students and classes', 'menu_sections' => 'Classes and sections', 'menu_promotions' => 'Move up a class'],
        'madrasa' => ['menu_top' => 'Students and classes', 'menu_sections' => 'Classes and sections', 'menu_promotions' => 'Move up a class'],
        'college' => ['menu_top' => 'Students and years', 'menu_sections' => 'Years and groups', 'menu_promotions' => 'Move up a year'],
        'university' => ['menu_top' => 'Students and semesters', 'menu_sections' => 'Semesters and sections', 'menu_promotions' => 'Move up a semester', 'menu_admissions' => 'Admissions (applications)'],
        'coaching' => ['menu_top' => 'Students and batches', 'menu_sections' => 'Courses and batches'],
    ],
];
