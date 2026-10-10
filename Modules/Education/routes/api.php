<?php

use Illuminate\Support\Facades\Route;
use Modules\Education\Http\Controllers\AdmissionController;
use Modules\Education\Http\Controllers\EnrollmentController;
use Modules\Education\Http\Controllers\FieldController;
use Modules\Education\Http\Controllers\OverviewController;
use Modules\Education\Http\Controllers\PromotionController;
use Modules\Education\Http\Controllers\SetupController;
use Modules\Education\Http\Controllers\StructureController;
use Modules\Education\Http\Controllers\StudentController;

/*
| Education API (loaded by EducationServiceProvider under /api, group "api"):
| a signed-in organization context, Education on there (403 otherwise), and
| the permission checked in each controller at the unit in the address.
| Unknown structure kinds and presets are a 404 in the controller, after the
| organization check (parameters are not constrained in the address).
*/

Route::middleware(['auth:sanctum', 'org', 'module:education'])
    ->prefix('organizations/{organization}/education')
    ->group(function () {
        Route::get('setup', [SetupController::class, 'show']);
        Route::get('overview', OverviewController::class);
        Route::get('structure/{kind}', [StructureController::class, 'index']);
        Route::get('fields', [FieldController::class, 'index']);
        Route::get('students', [StudentController::class, 'index']);
        Route::get('students/{student}', [StudentController::class, 'show']);
        Route::get('sections/{section}/students', [EnrollmentController::class, 'roster']);
        Route::get('admissions', [AdmissionController::class, 'index']);
        Route::get('admissions/{admission}', [AdmissionController::class, 'show']);
        Route::get('promotions', [PromotionController::class, 'index']);
        Route::get('promotions/{batch}', [PromotionController::class, 'show']);
        // Checking a file and then making it: a few per minute.
        Route::post('students/import', [StudentController::class, 'import'])->middleware('throttle:education-import');

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('promotions', [PromotionController::class, 'store']);
            Route::post('promotions/{batch}/lines', [PromotionController::class, 'decide']);
            // Unknown steps are refused by the controller, after the organization checks.
            Route::post('promotions/{batch}/{step}', [PromotionController::class, 'step']);

            Route::post('presets/{preset}/apply', [SetupController::class, 'applyPreset']);
            Route::post('structure/{kind}', [StructureController::class, 'store']);
            Route::patch('structure/{kind}/{id}', [StructureController::class, 'update']);
            Route::delete('structure/{kind}/{id}', [StructureController::class, 'destroy']);
            Route::post('fields', [FieldController::class, 'store']);
            Route::patch('fields/{field}', [FieldController::class, 'update']);

            Route::post('students', [StudentController::class, 'store']);
            Route::patch('students/{student}', [StudentController::class, 'update']);
            Route::post('students/{student}/leave', [StudentController::class, 'leave']);
            Route::post('students/{student}/guardians', [StudentController::class, 'linkGuardian']);
            Route::patch('students/{student}/guardians/{guardian}', [StudentController::class, 'updateGuardian']);
            Route::delete('students/{student}/guardians/{guardian}', [StudentController::class, 'unlinkGuardian']);
            Route::post('students/{student}/photo', [StudentController::class, 'storePhoto']);

            Route::post('enrollments/{enrollment}/place', [EnrollmentController::class, 'place']);
            Route::post('sections/{section}/rolls', [EnrollmentController::class, 'renumber']);

            Route::post('admissions', [AdmissionController::class, 'store']);
            Route::patch('admissions/{admission}', [AdmissionController::class, 'update']);
            Route::post('admissions/{admission}/step', [AdmissionController::class, 'step']);
            Route::post('admissions/{admission}/admit', [AdmissionController::class, 'admit']);
        });
    });
