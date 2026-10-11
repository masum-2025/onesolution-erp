<?php

/*
| Grading preset: Bangladesh universities, the UGC uniform grading (CGPA 4).
| An institution copies and changes it.
*/

return [
    'code' => 'bd_university_ugc',
    'name' => ['en' => 'CGPA 4 (Bangladesh universities, UGC)', 'bn' => 'সিজিপিএ ৪ (বাংলাদেশের বিশ্ববিদ্যালয়, ইউজিসি)'],
    'max_point_centi' => 400,
    'bands' => [
        ['min_percent_bp' => 8000, 'letter' => 'A+', 'point_centi' => 400, 'is_pass' => true],
        ['min_percent_bp' => 7500, 'letter' => 'A', 'point_centi' => 375, 'is_pass' => true],
        ['min_percent_bp' => 7000, 'letter' => 'A-', 'point_centi' => 350, 'is_pass' => true],
        ['min_percent_bp' => 6500, 'letter' => 'B+', 'point_centi' => 325, 'is_pass' => true],
        ['min_percent_bp' => 6000, 'letter' => 'B', 'point_centi' => 300, 'is_pass' => true],
        ['min_percent_bp' => 5500, 'letter' => 'B-', 'point_centi' => 275, 'is_pass' => true],
        ['min_percent_bp' => 5000, 'letter' => 'C+', 'point_centi' => 250, 'is_pass' => true],
        ['min_percent_bp' => 4500, 'letter' => 'C', 'point_centi' => 225, 'is_pass' => true],
        ['min_percent_bp' => 4000, 'letter' => 'D', 'point_centi' => 200, 'is_pass' => true],
        ['min_percent_bp' => 0, 'letter' => 'F', 'point_centi' => 0, 'is_pass' => false],
    ],
];
