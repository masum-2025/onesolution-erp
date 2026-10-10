<?php

// Course registration messages (API errors, checks, results).
return [
    'errors' => [
        'not_company_unit' => 'Open an institution or one of its campuses: a group has no students of its own.',
        'student_not_found' => 'That student does not exist here.',
        'session_not_found' => 'That session does not exist here.',
        'section_not_found' => 'That section does not exist here.',
        'offering_not_found' => 'That offered subject does not exist here.',
        'registration_not_found' => 'That registration does not exist here.',
        'item_not_found' => 'That subject is not on this registration.',
        'unit_not_found' => 'That campus is not part of this one.',
        'version_conflict' => 'Someone changed this a moment ago. Open it again to see the latest, then make your change.',
        'wrong_status' => 'This cannot be done now: it is :status.',
        'no_window' => 'Registration for this session has not been opened yet. Ask the academic office when it opens.',
        'window_closed' => 'Registration is open from :opens to :closes. Ask the academic office for a change now.',
        'add_drop_over' => 'Adding and dropping ended on :date. A subject can only be withdrawn now, with a reason.',
        'already_registered' => ':subject is already on this registration.',
        'prerequisites_missing' => ':subject needs :missing completed first.',
        'offering_full' => 'This group is full (:capacity seats). Choose another group, or ask for a seat.',
        'offering_not_open' => 'This subject is not open for registration.',
        'offering_elsewhere' => 'This subject is offered in another session or at another campus.',
        'credits_over' => 'This goes over :limit credits, the most allowed this session.',
        'credits_under' => 'Register at least :minimum credits before handing in.',
        'not_studying' => ':name is not studying in this session.',
        'self_registration_off' => 'Students do not register themselves here. The academic office registers your subjects.',
        'own_approval' => 'Someone else approves this registration: not the student, nor whoever handed it in.',
        'reason_needed' => 'Say why the subject is withdrawn.',
    ],
    'validation' => [
        'reference' => 'Choose one of this institution\'s own entries.',
        'group_taken' => 'This subject already has a group with this name in this session at this campus.',
        'capacity_below' => ':count students hold a seat: the seats cannot be fewer.',
        'cancel_in_use' => 'Students hold a seat or wait for one. Move them to another group first.',
        'window_order' => 'Registration must close on or after the day it opens.',
        'add_drop_inside' => 'Adding and dropping must end after registration opens and by the end of the session (:ends).',
    ],
    'messages' => [
        'offered' => ':count subject(s) offered.',
    ],
];
