<?php

// Rule labels (manifest "rules"), key = part after "education_exams.".
return [
    'default_grading_scheme' => [
        'label' => 'Grading a new exam starts with',
        'description' => 'The code of one of the institution\'s grading schemes (e.g. bd_school_gpa5, bd_university_ugc, percentage). Each exam may pick another.',
    ],
    'moderation_required' => [
        'label' => 'Marks need approval',
        'description' => 'On: marks a teacher hands in wait for someone else to approve them or send them back. Off: handing in approves them.',
    ],
    'categories' => [
        'grading' => 'Grading',
        'marks' => 'Marks',
    ],
];
