<?php

// Students' attendance messages and errors.
return [
    'errors' => [
        'not_company_unit' => 'Open an institution or one of its campuses: a group has no classes of its own.',
        'section_not_found' => 'That section does not exist here, or it is not yours to see.',
        'period_not_found' => 'That period does not exist at this section\'s campus.',
        'holiday_not_found' => 'That holiday does not exist here.',
        'student_not_found' => 'That student does not exist here, or is not yours to see.',
        'version_conflict' => 'Someone saved this attendance a moment ago. It is fresh now; check it and save again.',
        'not_your_section' => 'You can take attendance only of your own section (as its class teacher) or of the subjects you teach there. Ask the head teacher.',
        'future_day' => 'Attendance cannot be taken for a day still to come.',
        'outside_window' => 'Teachers can take or change attendance only for today and :days days before. Ask the office to change an older day.',
        'outside_session' => 'That day is outside this section\'s session.',
        'period_needed' => 'Attendance here is taken in each period: choose the period.',
        'period_not_used' => 'Attendance here is taken once a day, not by period.',
        'period_inactive' => 'That period is switched off. Choose another, or switch it on in the periods list.',
        'period_in_use' => 'Attendance was taken in this period, so it cannot be removed. Switch it off instead.',
        'subject_not_in_section' => 'That subject is not taught in this section.',
        'student_not_in_section' => 'Some students on this sheet are not in the section. Open the sheet again to get its students now.',
    ],
    'validation' => [
        'date' => 'Give a date like 2026-03-10.',
        'unit' => 'Choose this campus or one below it.',
        'levels' => 'Choose classes of this institution.',
        'period_number_taken' => 'This campus already has a period with that number.',
        'holiday_too_long' => 'One holiday can be at most :days days long. Add a long vacation in parts.',
    ],
    // One or two letters for registers and CSV.
    'short' => [
        'present' => 'P',
        'absent' => 'A',
        'late' => 'L',
        'leave' => 'LV',
    ],
    'csv' => [
        'roll' => 'Roll',
        'name' => 'Name',
        'code' => 'ID',
        'days' => 'Days',
    ],
];
