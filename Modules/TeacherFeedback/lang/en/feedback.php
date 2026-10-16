<?php

// Teacher feedback messages, errors and the ready-made questions.
return [
    'thanks' => 'Thank you. Your rating is kept without your name.',
    'default_questions' => [
        'explains' => 'Explains the lessons clearly',
        'on_time' => 'Comes to class on time',
        'answers' => 'Answers questions patiently',
        'manner' => 'Treats students kindly and fairly',
        'homework' => 'Checks homework and copies',
    ],
    'errors' => [
        'not_company_unit' => 'Open an institution or one of its campuses: a group has no teachers of its own.',
        'round_not_found' => 'That feedback round does not exist here.',
        'comment_not_found' => 'That comment is not in this round.',
        'student_not_found' => 'No student is linked to your login here, or they are not in a section yet. Ask the school.',
        'results_not_found' => 'Your institution does not show teachers their own feedback.',
        'version_conflict' => 'Someone changed this round a moment ago. It is fresh now; make your change again.',
        'wrong_status' => 'This cannot be done now: the round has moved on (only a draft\'s questions change; once open only its last day moves).',
        'round_closed' => 'This round is open from :opens to :closes.',
        'not_your_turn' => 'In this round the school asks someone else to rate (students or guardians).',
        'not_their_teacher' => 'That teacher does not teach this subject in the student\'s section.',
        'already_rated' => 'You have already rated this teacher for this subject in this round.',
        'unanswered_questions' => 'Give stars for every question.',
        'no_questions' => 'Add at least one question before opening the round.',
    ],
    'validation' => [
        'session' => 'Choose a session of this institution.',
        'dates' => 'The last day must be on or after the first day.',
    ],
];
