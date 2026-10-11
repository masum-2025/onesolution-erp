<?php

use Illuminate\Support\Facades\Route;
use Modules\EducationExams\Http\Controllers\ExamController;
use Modules\EducationExams\Http\Controllers\MarkSheetController;

/*
| Exams API (loaded by EducationExamsServiceProvider under /api, group
| "api"): a signed-in organization context, Education and exams on there
| (403 otherwise), and the permission checked in each controller at the
| unit in the address. Teachers save marks often: their own limit.
*/

Route::middleware(['auth:sanctum', 'org', 'module:education', 'module:education_exams'])
    ->prefix('organizations/{organization}/exams')
    ->group(function () {
        Route::get('setup', [ExamController::class, 'setup']);
        Route::get('/', [ExamController::class, 'index']);
        Route::get('sheets/{sheet}', [MarkSheetController::class, 'show']);
        Route::get('{exam}', [ExamController::class, 'show']);
        Route::get('{exam}/sheets', [MarkSheetController::class, 'index']);

        Route::middleware('throttle:education-exams-marks')->group(function () {
            Route::put('sheets/{sheet}/marks', [MarkSheetController::class, 'save']);
            Route::post('sheets/{sheet}/submit', [MarkSheetController::class, 'submit']);
        });

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('schemes', [ExamController::class, 'storeScheme']);
            Route::patch('schemes/{scheme}', [ExamController::class, 'updateScheme']);
            Route::post('/', [ExamController::class, 'store']);
            Route::patch('{exam}', [ExamController::class, 'update']);
            Route::post('{exam}/status', [ExamController::class, 'status']);
            Route::post('{exam}/papers', [ExamController::class, 'storePaper']);
            Route::post('{exam}/papers/from-curriculum', [ExamController::class, 'fromCurriculum']);
            Route::patch('{exam}/papers/{paper}', [ExamController::class, 'updatePaper']);
            Route::delete('{exam}/papers/{paper}', [ExamController::class, 'deletePaper']);
            Route::post('sheets/{sheet}/approve', [MarkSheetController::class, 'approve']);
            Route::post('sheets/{sheet}/send-back', [MarkSheetController::class, 'sendBack']);
            Route::post('sheets/{sheet}/assign', [MarkSheetController::class, 'assign']);
        });
    });
