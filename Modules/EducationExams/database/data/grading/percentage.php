<?php

/*
| Grading preset: percentage only, pass or fail (no grade points). The pass
| line is a starting point; an institution changes it.
*/

return [
    'code' => 'percentage',
    'name' => ['en' => 'Percentage (pass or fail)', 'bn' => 'শতাংশ (পাস বা ফেল)'],
    'max_point_centi' => 0,
    'bands' => [
        ['min_percent_bp' => 4000, 'letter' => 'P', 'point_centi' => 0, 'is_pass' => true],
        ['min_percent_bp' => 0, 'letter' => 'F', 'point_centi' => 0, 'is_pass' => false],
    ],
];
