<?php

// Class lecture messages and errors.
return [
    'deleted' => 'Draft deleted.',
    'errors' => [
        'not_company_unit' => 'Open an institution or one of its campuses: a group has no classes of its own.',
        'lecture_not_found' => 'That lecture does not exist here, or it is not yours to see.',
        'section_not_found' => 'That section does not exist here.',
        'file_not_found' => 'That file is not on this lecture.',
        'student_not_found' => 'No student is linked to your login here, or they are not in a section yet. Ask the school.',
        'version_conflict' => 'Someone changed this lecture a moment ago. It is fresh now; make your change again.',
        'wrong_status' => 'This cannot be done now: the lecture has moved on. Look at it again.',
        'not_your_subject' => 'You can post and change lectures only for the subjects you teach in that section. Ask the head teacher to name you as its teacher.',
        'subject_not_in_section' => 'That subject is not taught in this section (it is not in the class\'s curriculum).',
        'own_approval' => 'You posted this lecture, so someone else approves it.',
        'too_many_files' => 'A lecture can have :max files. Remove one first.',
    ],
    'validation' => [
        'file_size' => 'This file is too big: a lecture file may be up to :max MB.',
        'file_type' => 'Add a PDF, a picture (JPG, PNG, WebP), a Word (.docx) or a PowerPoint (.pptx) file.',
        'video' => 'Give an https link to a video on :sites.',
    ],
];
