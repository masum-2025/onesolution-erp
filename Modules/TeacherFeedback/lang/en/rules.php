<?php

// Rule labels, key = part after "teacher_feedback.".
return [
    'who_rates' => [
        'label' => 'Who rates the teachers',
        'description' => 'Students themselves, their guardians, or both. Fixed for a round when it opens.',
    ],
    'min_responses' => [
        'label' => 'Ratings before results show',
        'description' => 'A teacher\'s averages and comments show only once at least this many people rated, so nobody can be picked out.',
    ],
    'allow_comments' => [
        'label' => 'Allow written comments',
        'description' => 'Besides the stars, people may write a comment; someone who manages feedback approves it before the teacher sees it.',
    ],
    'teachers_see_own' => [
        'label' => 'Teachers see their own feedback',
        'description' => 'Teachers see their own averages and approved comments, never who gave them.',
    ],
    'categories' => [
        'feedback' => 'Feedback',
        'privacy' => 'Privacy',
    ],
];
