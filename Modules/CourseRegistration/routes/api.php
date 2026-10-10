<?php

use Illuminate\Support\Facades\Route;
use Modules\CourseRegistration\Http\Controllers\OfferingController;
use Modules\CourseRegistration\Http\Controllers\PortalRegistrationController;
use Modules\CourseRegistration\Http\Controllers\RegistrationController;

/*
| Course registration API (loaded by CourseRegistrationServiceProvider under
| /api, group "api"): a signed-in organization context, Education and course
| registration on there (403 otherwise), and the permission checked in each
| controller at the unit in the address.
*/

Route::middleware(['auth:sanctum', 'org', 'module:education', 'module:course_registration'])
    ->prefix('organizations/{organization}/course-registration')
    ->group(function () {
        Route::get('setup', [OfferingController::class, 'setup']);
        Route::get('students', [OfferingController::class, 'findStudents']);
        Route::get('sections', [OfferingController::class, 'sections']);
        Route::get('offerings', [OfferingController::class, 'index']);
        Route::get('offerings/{offering}/students', [OfferingController::class, 'students']);
        Route::get('registrations', [RegistrationController::class, 'index']);
        Route::get('registrations/{registration}', [RegistrationController::class, 'show']);

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::put('sessions/{session}/window', [OfferingController::class, 'saveWindow']);
            Route::post('offerings', [OfferingController::class, 'store']);
            Route::post('offerings/from-curriculum', [OfferingController::class, 'fromCurriculum']);
            Route::patch('offerings/{offering}', [OfferingController::class, 'update']);

            Route::post('registrations/{registration}/approve', [RegistrationController::class, 'approve']);
            Route::post('registrations/{registration}/send-back', [RegistrationController::class, 'sendBack']);
            Route::post('sections/{section}/register', [RegistrationController::class, 'registerSection']);
        });

        // An office registering many students' subjects: its own, larger limit per person.
        Route::middleware('throttle:course-registration-staff')->group(function () {
            Route::post('registrations', [RegistrationController::class, 'open']);
            Route::post('registrations/{registration}/items', [RegistrationController::class, 'add']);
            Route::post('registrations/{registration}/submit', [RegistrationController::class, 'submit']);
            Route::post('items/{item}/drop', [RegistrationController::class, 'drop']);
            Route::post('items/{item}/outcome', [RegistrationController::class, 'outcome']);
        });
    });

/*
| A student registering themselves in the client's portal (B2B2C): portal
| members only, with the portal, Education and course registration on; many
| students at once when the window opens, so a few writes a minute each.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:education', 'module:course_registration'])
    ->prefix('portal/course-registration')
    ->group(function () {
        Route::get('/', [PortalRegistrationController::class, 'show']);
        Route::middleware('throttle:course-registration-portal')->group(function () {
            Route::post('items', [PortalRegistrationController::class, 'add']);
            Route::post('items/{item}/drop', [PortalRegistrationController::class, 'drop']);
            Route::post('submit', [PortalRegistrationController::class, 'submit']);
        });
    });
