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
        'access' => 'Who sees results',
        'families' => 'Families',
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
    'class_teacher_sees_section_results' => [
        'label' => 'Class teachers see their whole section\\x27s results',
        'description' => 'On: a class teacher sees every subject, the GPA and positions of their section. Off: only the subjects they enter marks for. Exam office, head and moderators always see all.',
    ],
    'subject_teacher_sees_section_results' => [
        'label' => 'Subject teachers see the whole section\\x27s results',
        'description' => 'On: a teacher sees every subject, the GPA and positions of the sections they teach. Off: only their own subject\\x27s marks and grades.',
    ],
    'notify_results' => [
        'label' => 'Tell families when results are published',
        'description' => 'On: families get the student\'s GPA and a link to the portal by SMS or mail when an exam or a final result is published. SMS costs money; off by default.',
    ],
    'withhold_results_with_dues' => [
        'label' => 'Hold results of students who owe fees',
        'description' => 'On (with fees on): a student who owes fees sees no result in the portal and the message asks the family to contact the office. Staff still see every result.',
    ],
    'marksheet_signatures' => [
        'label' => 'Who signs a printed marksheet',
        'description' => 'The signature lines at the bottom of a marksheet, in order: class teacher, exam controller, head, guardian.',
    ],
];
