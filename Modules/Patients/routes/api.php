<?php

use Illuminate\Support\Facades\Route;
use Modules\Patients\Http\Controllers\MergeController;
use Modules\Patients\Http\Controllers\PatientController;
use Modules\Patients\Http\Controllers\PortalPatientController;
use Modules\Patients\Http\Controllers\SetupController;

/*
| Patients API: a signed-in organization context, the module on there (403
| otherwise), and the permission checked in each controller at the unit in
| the address. Searching is limited (patients-search), so is writing.
*/

Route::middleware(['auth:sanctum', 'org', 'module:patients'])
    ->prefix('organizations/{organization}/patients')
    ->group(function () {
        Route::get('setup', SetupController::class);
        Route::get('patients', [PatientController::class, 'index'])->middleware('throttle:patients-search');
        Route::get('patients/{patient}', [PatientController::class, 'show']);
        Route::get('patients/{patient}/timeline', [PatientController::class, 'timeline']);
        Route::get('patients/{patient}/card', [PatientController::class, 'card']);
        Route::get('patients/{patient}/views', [PatientController::class, 'views']);
        Route::get('merges', [MergeController::class, 'index']);

        Route::middleware('throttle:patients-writes')->group(function () {
            Route::post('patients', [PatientController::class, 'store']);
            Route::patch('patients/{patient}', [PatientController::class, 'update']);
            Route::post('patients/{patient}/merge', [MergeController::class, 'store']);
            Route::post('merges/{merge}/decide', [MergeController::class, 'decide']);
        });
    });

/*
| A patient's own record and history in the client's portal (B2B2C): portal
| members only (the API refuses them everything else), with the portal and
| Patients on.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:patients'])
    ->prefix('portal/patients')
    ->group(function () {
        Route::get('/', PortalPatientController::class);
    });
