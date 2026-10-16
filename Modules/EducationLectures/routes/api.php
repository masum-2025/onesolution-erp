<?php

use Illuminate\Support\Facades\Route;
use Modules\EducationLectures\Http\Controllers\LectureController;
use Modules\EducationLectures\Http\Controllers\PortalLectureController;

/*
| Class lectures API (loaded by EducationLecturesServiceProvider under /api,
| group "api"): a signed-in organization context, Education and lectures on
| there (403 otherwise), and the permission checked in each controller at
| the unit in the address.
*/

Route::middleware(['auth:sanctum', 'org', 'module:education', 'module:education_lectures'])
    ->prefix('organizations/{organization}/lectures')
    ->group(function () {
        Route::get('setup', [LectureController::class, 'setup']);
        Route::get('/', [LectureController::class, 'index']);
        Route::get('{lecture}', [LectureController::class, 'show']);

        Route::middleware('throttle:education-lectures-writes')->group(function () {
            Route::post('/', [LectureController::class, 'store']);
            Route::patch('{lecture}', [LectureController::class, 'update']);
            Route::delete('{lecture}', [LectureController::class, 'destroy']);
            Route::post('{lecture}/publish', [LectureController::class, 'publish']);
            Route::post('{lecture}/approve', [LectureController::class, 'approve']);
            Route::post('{lecture}/withdraw', [LectureController::class, 'withdraw']);
            Route::delete('{lecture}/files/{file}', [LectureController::class, 'removeFile']);
        });
        Route::post('{lecture}/files', [LectureController::class, 'addFile'])->middleware('throttle:education-lectures-uploads');
    });

/*
| A student's (or their child's) lectures in the client's portal (B2B2C):
| portal members only, with the portal, Education and lectures on.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:education', 'module:education_lectures'])
    ->get('portal/lectures', [PortalLectureController::class, 'index']);
