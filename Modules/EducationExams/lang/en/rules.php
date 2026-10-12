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
        'results' => 'Results',
    ],
    'fail_any_subject' => [
        'label' => 'Failing a subject fails the result',
        'description' => 'On: a student who fails any counted subject fails overall (grade point 0), as in SSC and HSC.',
    ],
    'optional_bonus_above' => [
        'label' => 'Optional (4th) subject bonus above',
        'description' => 'Grade points an optional subject earns above this are added (in hundredths: 200 = 2.00). Empty: no bonus.',
    ],
    'gpa_by_credits' => [
        'label' => 'Weigh grade points by credits',
        'description' => 'On: subjects count by their credits (universities). Off: each subject counts once.',
    ],
    'show_rank' => [
        'label' => 'Show merit positions',
        'description' => 'Positions in the class and the section on results and marksheets.',
    ],
    'correction_needs_approval' => [
        'label' => 'Corrections need a second person',
        'description' => 'On: changing a published mark waits for someone other than who asked.',
    ],
];
