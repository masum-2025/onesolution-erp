<?php

use Illuminate\Support\Facades\Route;
use Modules\Recruitment\Http\Controllers\ApplicationController;
use Modules\Recruitment\Http\Controllers\CareersController;
use Modules\Recruitment\Http\Controllers\InterviewController;
use Modules\Recruitment\Http\Controllers\OfferController;
use Modules\Recruitment\Http\Controllers\OpeningController;

/*
| Recruitment API (loaded by RecruitmentServiceProvider under /api, group
| "api"): a signed-in organization context, HRM and Recruitment on there
| (403 otherwise), and the permission checked in each controller at the
| unit concerned.
*/

Route::middleware(['auth:sanctum', 'org', 'module:hrm', 'module:recruitment'])
    ->prefix('organizations/{organization}/recruitment')
    ->group(function () {
        Route::get('options', [OpeningController::class, 'options']);
        Route::get('careers', [CareersController::class, 'show']);
        Route::get('openings', [OpeningController::class, 'index']);
        Route::get('openings/{opening}', [OpeningController::class, 'show']);
        Route::get('applications', [ApplicationController::class, 'index']);
        Route::get('applications/{application}', [ApplicationController::class, 'show']);
        Route::get('applications/{application}/interviewers', [ApplicationController::class, 'interviewers']);
        Route::get('applications/{application}/people', [ApplicationController::class, 'people']);
        Route::get('interviews/mine', [InterviewController::class, 'mine']);
        Route::get('offers/{offer}', [OfferController::class, 'show']);
        Route::get('letter', [OfferController::class, 'letter']);

        // CVs are read and kept: a few a minute (before applications/{application}/{step}, which would take the path).
        Route::post('applications/{application}/files', [ApplicationController::class, 'addFile'])->middleware('throttle:recruitment-uploads');

        Route::middleware('throttle:recruitment-writes')->group(function () {
            Route::put('careers', [CareersController::class, 'update']);
            Route::post('openings', [OpeningController::class, 'store']);
            Route::patch('openings/{opening}', [OpeningController::class, 'update']);
            Route::post('openings/{opening}/{step}', [OpeningController::class, 'step']);
            Route::post('applications', [ApplicationController::class, 'store']);
            Route::patch('applications/{application}', [ApplicationController::class, 'update']);
            Route::post('applications/{application}/hire', [ApplicationController::class, 'hire']);
            Route::post('applications/{application}/interviews', [ApplicationController::class, 'schedule']);
            Route::post('applications/{application}/offers', [OfferController::class, 'store']);
            Route::post('applications/{application}/{step}', [ApplicationController::class, 'end']);
            Route::post('interviews/{interview}/feedback', [InterviewController::class, 'feedback']);
            Route::post('interviews/{interview}/cancel', [InterviewController::class, 'cancel']);
            Route::patch('offers/{offer}', [OfferController::class, 'update']);
            Route::post('offers/{offer}/{step}', [OfferController::class, 'step']);
            Route::put('letter', [OfferController::class, 'saveLetter']);
        });
    });
