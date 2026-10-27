<?php

return [
    'errors' => [
        'not_company_unit' => 'Appraisals belong to a company. Open a company or one of its branches.',
        'not_linked' => 'Your login is not linked to an employee. Ask HR to link it.',
        'cycle_not_found' => 'This review cycle was not found.',
        'review_not_found' => 'This appraisal was not found.',
        'competency_not_found' => 'This competency was not found.',
        'not_draft' => 'Only a draft cycle can be changed like this (an open one: only its last days).',
        'not_open' => 'This review cycle is not open.',
        'nobody' => 'Nobody works at this place today. Choose another unit.',
        'wrong_stage' => 'This appraisal is at another step now. Reload to see where it stands.',
        'not_yours' => 'This step of the appraisal is not yours to do.',
        'version_conflict' => 'Someone changed this in the meantime. Reload and try again.',
    ],
    'validation' => [
        'code_taken' => 'This code is already used by another competency.',
        'period' => 'The period cannot end before it starts.',
        'after_goals' => 'The self review cannot end before the goals.',
        'after_self' => 'The manager review cannot end before the steps before it.',
        'rating_unknown' => 'This rating is not part of the appraisal.',
        'rating_scale' => 'Choose a rating from the scale.',
        'no_goals' => 'Write at least one goal.',
        'total_100' => 'The goals\' weights add up to :total; they must add up to 100.',
        'unrated' => '{1} One item has no rating yet.|[2,*] :count items have no rating yet.',
    ],
    'stages' => [
        'goals' => 'Goals',
        'self' => 'Self review',
        'manager' => 'Manager review',
        'acknowledge' => 'Acknowledge the result',
        'done' => 'Done',
    ],
    'competencies_added' => '{0} No competencies were missing.|{1} One competency added.|[2,*] :count competencies added.',
];
