<?php

return [
    'templates' => [
        'recruitment_applied' => [
            'subject' => 'New application for {{ job }}',
            'body' => 'Someone applied for {{ job }} at {{ organization }} and confirmed their email.

Open the opening to see the CV.',
            'action' => 'Open the opening',
        ],
        'recruitment_confirm' => [
            'subject' => 'Confirm your application for {{ job }}',
            'body' => 'Hello {{ name }},

Thank you for applying for {{ job }} at {{ organization }}. Please confirm your email address within {{ hours }} hours; until then your application is not sent to the company.

If you did not apply, ignore this mail.',
            'action' => 'Confirm my application',
        ],
        'recruitment_interview_scheduled' => [
            'subject' => 'Interview for {{ job }}: {{ when }}',
            'body' => 'You are asked to interview a candidate for {{ job }} at {{ organization }}.

When: {{ when }}
How: {{ mode }}
Where: {{ place }}

Give your feedback after the interview.',
            'action' => 'Open my interviews',
        ],
        'recruitment_interview_invite' => [
            'subject' => 'Your interview for {{ job }}',
            'body' => 'Hello {{ name }},

{{ organization }} invites you to an interview for {{ job }}.

When: {{ when }}
How: {{ mode }}
Where: {{ place }}

Reply to the company if the time does not suit you.',
            'action' => 'Open',
        ],
        'recruitment_not_selected' => [
            'subject' => 'Your application for {{ job }}',
            'body' => 'Hello {{ name }},

Thank you for your interest in {{ job }} at {{ organization }}. After careful thought, we will not take your application further this time.

We wish you all the best.',
            'action' => 'Open',
        ],
        'recruitment_offer_to_approve' => [
            'subject' => 'A job offer for {{ job }} to approve',
            'body' => 'A job offer for {{ job }} at {{ organization }} waits for your approval.

Open it to read the letter and approve it or send it back.',
            'action' => 'Open the opening',
        ],
        'recruitment_offer_sent' => [
            'subject' => 'Your job offer for {{ job }}',
            'body' => 'Hello {{ name }},

{{ organization }} offers you the job of {{ job }}. Read the letter and give your answer by {{ respond_by }}.',
            'action' => 'Read my offer',
        ],
        'recruitment_offer_answered' => [
            'subject' => 'Job offer for {{ job }}: {{ answer }}',
            'body' => 'The candidate answered the job offer for {{ job }} at {{ organization }}: {{ answer }}.',
            'action' => 'Open the opening',
        ],
    ],
    'catalog' => [
        'recruitment_applied' => [
            'name' => 'New job application',
            'description' => 'To the people who manage recruitment, when someone confirms an application (no name or contact in the mail).',
        ],
        'recruitment_confirm' => [
            'name' => 'Confirm a job application',
            'description' => 'To the applicant: the link that confirms their email.',
        ],
        'recruitment_interview_scheduled' => [
            'name' => 'Interview scheduled (interviewers)',
            'description' => 'To the people asked to interview.',
        ],
        'recruitment_interview_invite' => [
            'name' => 'Interview invitation',
            'description' => 'To the candidate, when HR wishes.',
        ],
        'recruitment_not_selected' => [
            'name' => 'Application not taken further',
            'description' => 'To the candidate, when HR rejects and wishes to tell them.',
        ],
        'recruitment_offer_to_approve' => [
            'name' => 'Job offer to approve',
            'description' => 'To people who may hire, when an offer waits for approval (no name or salary in the mail).',
        ],
        'recruitment_offer_sent' => [
            'name' => 'Job offer',
            'description' => 'To the candidate, with the link to read and answer the offer.',
        ],
        'recruitment_offer_answered' => [
            'name' => 'Job offer answered',
            'description' => 'To the people who wrote and approved the offer and manage recruitment.',
        ],
    ],
];
