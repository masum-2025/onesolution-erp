<?php

// Rule labels (manifest "rules"), key = part after "course_registration.".
return [
    'min_credits' => ['label' => 'Fewest credits a session', 'description' => 'A registration is handed in with at least this many credits. 0: no lower limit.'],
    'max_credits' => ['label' => 'Most credits a session', 'description' => 'More than this is an overload and needs approval. 0: no upper limit.'],
    'overload_credits' => ['label' => 'Overload allowed (credits)', 'description' => 'How many credits above the most a student may take, with approval.'],
    'approval_required' => ['label' => 'Registrations need approval', 'description' => 'On: an advisor approves each registration. Off: handing in approves it (an overload still needs approval).'],
    'prerequisites_enforced' => ['label' => 'Check prerequisites', 'description' => 'A subject is taken only when the subjects it needs were completed.'],
    'waitlist' => ['label' => 'Waiting lists', 'description' => 'A full group puts students on a waiting list; a freed seat goes to the first one, who is told.'],
    'self_registration' => ['label' => 'Students register themselves', 'description' => 'Students pick their subjects in the portal while the registration window of the session is open.'],
    'categories' => ['credits' => 'Credits', 'registration' => 'Registration'],
];
