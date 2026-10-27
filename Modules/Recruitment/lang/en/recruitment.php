<?php

return [
    'errors' => [
        'not_company_unit' => 'Recruitment belongs to a company. Open a company or one of its branches.',
        'opening_not_found' => 'This job opening was not found.',
        'application_not_found' => 'This application was not found.',
        'interview_not_found' => 'This interview was not found.',
        'file_not_found' => 'This file was not found.',
        'not_open' => 'This job is not taking applications now.',
        'finished' => 'This can no longer be changed. Reload to see where it stands.',
        'already_applied' => 'This person has already applied for this job.',
        'too_many_applications' => 'Too many applications from this email today. Try again tomorrow.',
        'cannot_feedback' => 'Only the people asked to this interview can give feedback on it.',
        'slug_taken' => 'This address is already used. Choose another one.',
        'version_conflict' => 'Someone changed this in the meantime. Reload and try again.',
    ],
    'validation' => [
        'unit' => 'Choose a unit of this company.',
        'position' => 'Choose a position from the list.',
        'salary_range' => 'The highest salary cannot be below the lowest.',
        'vacancies' => ':hired people are already hired; the number cannot be lower.',
        'closes_past' => 'The last day to apply has passed. Choose a later day or clear it.',
        'stage' => 'Choose a step from the list.',
        'interviewers' => 'Choose people who may interview here.',
        'past' => 'Choose a time in the future.',
        'cv_size' => 'The file is too big. At most :max KB.',
        'cv_kind' => 'Only PDF and Word files can be CVs.',
    ],
    'modes' => [
        'in_person' => 'In person',
        'phone' => 'By phone',
        'video' => 'By video call',
    ],
    'hired_reason' => 'Hired through recruitment: :job',
];
