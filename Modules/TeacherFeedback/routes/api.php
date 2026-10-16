<?php

use Illuminate\Support\Facades\Route;
use Modules\TeacherFeedback\Http\Controllers\MyFeedbackController;
use Modules\TeacherFeedback\Http\Controllers\PortalFeedbackController;
use Modules\TeacherFeedback\Http\Controllers\RoundController;

/*
| Teacher feedback API (loaded by TeacherFeedbackServiceProvider under /api,
| group "api"): a signed-in organization context, Education and teacher
| feedback on there (403 otherwise), and the permission checked in each
| controller at the unit in the address.
*/

Route::middleware(['auth:sanctum', 'org', 'module:education', 'module:teacher_feedback'])
    ->prefix('organizations/{organization}/teacher-feedback')
    ->group(function () {
        Route::get('setup', [RoundController::class, 'setup']);
        Route::get('mine', MyFeedbackController::class);
        Route::get('rounds', [RoundController::class, 'index']);
        Route::get('rounds/{round}', [RoundController::class, 'show']);

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('rounds', [RoundController::class, 'store']);
            Route::patch('rounds/{round}', [RoundController::class, 'update']);
            Route::post('rounds/{round}/open', [RoundController::class, 'open']);
            Route::post('rounds/{round}/close', [RoundController::class, 'close']);
            Route::post('rounds/{round}/comments/{comment}/approve', [RoundController::class, 'approve']);
            Route::post('rounds/{round}/comments/{comment}/hide', [RoundController::class, 'hide']);
        });
    });

/*
| Rating in the client's portal (B2B2C): portal members only, with the
| portal, Education and teacher feedback on.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:education', 'module:teacher_feedback'])
    ->prefix('portal/teacher-feedback')
    ->group(function () {
        Route::get('/', [PortalFeedbackController::class, 'show']);
        Route::post('/', [PortalFeedbackController::class, 'rate'])->middleware('throttle:teacher-feedback-portal');
    });
