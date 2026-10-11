<?php

/*
| Grading preset: Bangladesh secondary and higher secondary (SSC/HSC) GPA 5.
| The published letter grade scale; an institution copies and changes it.
| min_percent_bp: from this percentage (basis points) up to the next band.
*/

return [
    'code' => 'bd_school_gpa5',
    'name' => ['en' => 'GPA 5 (Bangladesh schools and colleges)', 'bn' => 'জিপিএ ৫ (বাংলাদেশের স্কুল ও কলেজ)'],
    'max_point_centi' => 500,
    'bands' => [
        ['min_percent_bp' => 8000, 'letter' => 'A+', 'point_centi' => 500, 'is_pass' => true],
        ['min_percent_bp' => 7000, 'letter' => 'A', 'point_centi' => 400, 'is_pass' => true],
        ['min_percent_bp' => 6000, 'letter' => 'A-', 'point_centi' => 350, 'is_pass' => true],
        ['min_percent_bp' => 5000, 'letter' => 'B', 'point_centi' => 300, 'is_pass' => true],
        ['min_percent_bp' => 4000, 'letter' => 'C', 'point_centi' => 200, 'is_pass' => true],
        ['min_percent_bp' => 3300, 'letter' => 'D', 'point_centi' => 100, 'is_pass' => true],
        ['min_percent_bp' => 0, 'letter' => 'F', 'point_centi' => 0, 'is_pass' => false],
    ],
];
