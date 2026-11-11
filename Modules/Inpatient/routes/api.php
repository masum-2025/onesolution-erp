<?php

use Illuminate\Support\Facades\Route;
use Modules\Inpatient\Http\Controllers\AdmissionController;
use Modules\Inpatient\Http\Controllers\BedController;
use Modules\Inpatient\Http\Controllers\CareController;
use Modules\Inpatient\Http\Controllers\DischargeController;
use Modules\Inpatient\Http\Controllers\PortalDischargeController;
use Modules\Inpatient\Http\Controllers\SetupController;
use Modules\Inpatient\Http\Controllers\WardController;

/*
| Inpatient API (made by make:erp-module): a signed-in organization context, the module
| on there (403 otherwise), and the permission checked in each controller at the unit in the address.
*/

Route::middleware(['auth:sanctum', 'org', 'module:patients', 'module:patient_billing', 'module:inpatient'])
    ->prefix('organizations/{organization}/inpatient')
    ->group(function () {
        Route::get('setup', SetupController::class);
        Route::get('wards', [WardController::class, 'index']);
        Route::get('wards/{ward}', [WardController::class, 'show']);
        Route::get('beds', [BedController::class, 'index']);
        Route::get('beds/board', [BedController::class, 'board']);
        Route::get('admissions', [AdmissionController::class, 'index']);
        Route::get('admissions/options', [AdmissionController::class, 'options']);
        Route::get('admissions/{admission}', [AdmissionController::class, 'show']);
        Route::get('admissions/{admission}/care', [CareController::class, 'show']);
        Route::get('admissions/{admission}/discharge', [DischargeController::class, 'show']);
        Route::get('admissions/{admission}/discharge/paper', [DischargeController::class, 'paper']);
        Route::get('patients', [AdmissionController::class, 'patients'])->middleware('throttle:inpatient-writes');

        Route::middleware('throttle:inpatient-writes')->group(function () {
            Route::post('wards', [WardController::class, 'store']);
            Route::patch('wards/{ward}', [WardController::class, 'update']);
            Route::delete('wards/{ward}', [WardController::class, 'destroy']);
            Route::post('beds', [BedController::class, 'store'])->name('inpatient.beds.store');
            Route::patch('beds/{bed}', [BedController::class, 'update'])->name('inpatient.beds.update');
            Route::delete('beds/{bed}', [BedController::class, 'destroy']);
            Route::post('beds/{bed}/status', [BedController::class, 'status'])->name('inpatient.beds.status');
            Route::post('admissions', [AdmissionController::class, 'store'])->name('inpatient.admissions.store');
            Route::post('admissions/{admission}/move', [AdmissionController::class, 'move'])->name('inpatient.admissions.move');
            Route::post('admissions/{admission}/cancel', [AdmissionController::class, 'cancel'])->name('inpatient.admissions.cancel');
            Route::post('admissions/{admission}/rounds', [CareController::class, 'round'])->name('inpatient.rounds.store');
            Route::post('admissions/{admission}/nursing-notes', [CareController::class, 'nursingNote'])->name('inpatient.nursing.store');
            Route::post('admissions/{admission}/tests', [CareController::class, 'tests'])->name('inpatient.tests.store');
            Route::put('admissions/{admission}/discharge', [DischargeController::class, 'save'])->name('inpatient.discharges.save');
            Route::post('admissions/{admission}/discharge/finish', [DischargeController::class, 'finish'])->name('inpatient.discharges.finish');
            Route::post('admissions/{admission}/discharge/amend', [DischargeController::class, 'amend'])->name('inpatient.discharges.amend');
        });
    });

/*
| A patient's own discharge papers in the client's portal (B2B2C): portal members
| only, with the portal, Patients, Patient billing and Inpatient on.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:patients', 'module:patient_billing', 'module:inpatient'])
    ->prefix('portal/inpatient')
    ->group(function () {
        Route::get('discharges', PortalDischargeController::class);
    });
