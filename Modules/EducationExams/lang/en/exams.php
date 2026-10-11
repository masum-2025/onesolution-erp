<?php

// Exam messages (API errors, checks).
return [
    'errors' => [
        'not_company_unit' => 'Open an institution or one of its campuses: a group has no students of its own.',
        'exam_not_found' => 'That exam does not exist here.',
        'paper_not_found' => 'That paper does not exist here.',
        'part_not_found' => 'That part of the paper does not exist.',
        'sheet_not_found' => 'That mark sheet does not exist here.',
        'scheme_not_found' => 'That grading scheme does not exist here.',
        'teacher_not_found' => 'That teacher does not exist here.',
        'version_conflict' => 'Someone else changed this a moment ago. Reload to see their change, then try again.',
        'wrong_status' => 'This cannot be done while it is ":status".',
        'bands_incomplete' => 'Add a band starting at 0 % and give each band its own starting percentage.',
        'parts_mismatch' => 'The parts must add up to the paper\'s full marks (:full).',
        'no_papers' => 'Add at least one paper before opening the exam for marks.',
        'paper_has_marks' => 'Marks are already entered for this paper, so its marks and parts stay as they are.',
        'marks_too_high' => 'Marks in :part cannot be more than :full.',
        'not_your_sheet' => 'You do not enter this sheet. The teacher named on it (or the section\'s class teacher) does.',
        'own_approval' => 'You handed these marks in, so someone else approves them.',
        'sheet_incomplete' => ':count students still have no marks. Enter their marks or mark them absent.',
        'exam_not_marking' => 'The exam does not take marks now.',
        'student_not_in_section' => 'That student is not in this section.',
        'paper_exists' => 'This class already has a paper of that subject in the exam.',
    ],
];
