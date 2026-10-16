<?php

// Rule labels, key = part after "education_lectures.".
return [
    'approval_required' => [
        'label' => 'Lectures need approval',
        'description' => 'On: a teacher\'s lecture waits until someone who manages lectures approves it. Off: it is shown as soon as the teacher publishes it.',
    ],
    'max_file_kb' => [
        'label' => 'Largest lecture file (KB)',
        'description' => 'Each file of a lecture may be at most this big.',
    ],
    'max_files' => [
        'label' => 'Files per lecture',
        'description' => 'How many files one lecture may have. 0: no files.',
    ],
    'video_hosts' => [
        'label' => 'Video sites allowed',
        'description' => 'A lecture\'s video link must be on one of these sites. None: no video links.',
    ],
    'notify_on_publish' => [
        'label' => 'Tell families of new lectures',
        'description' => 'Students and guardians with a login get a mail when a lecture of their section is published.',
    ],
    'quiet_days' => [
        'label' => 'Days before a subject counts as quiet',
        'description' => 'The education home names subjects with no lecture for this many days.',
    ],
    'categories' => [
        'lectures' => 'Lectures',
        'files' => 'Files and videos',
    ],
];
