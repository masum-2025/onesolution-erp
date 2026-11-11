<?php

use Illuminate\Support\Facades\Route;
use Modules\Outpatient\Http\Controllers\ChamberController;
use Modules\Outpatient\Http\Controllers\ConsultationController;
use Modules\Outpatient\Http\Controllers\DoctorController;
use Modules\Outpatient\Http\Controllers\PortalPrescriptionController;
use Modules\Outpatient\Http\Controllers\QueueController;
use Modules\Outpatient\Http\Controllers\ScheduleController;
use Modules\Outpatient\Http\Controllers\SerialController;
use Modules\Outpatient\Http\Controllers\SetupController;
use Modules\Outpatient\Http\Controllers\TemplateController;

/*
| Outpatient API: a signed-in organization context, Patients, Patient billing and
| Outpatient on there (403 otherwise), and the permission checked in each controller
| at the unit in the address. Writes are limited.
*/

Route::middleware(['auth:sanctum', 'org', 'module:patients', 'module:patient_billing', 'module:outpatient'])
    ->prefix('organizations/{organization}/outpatient')
    ->group(function () {
        Route::get('setup', SetupController::class);
        Route::get('doctors', [DoctorController::class, 'index']);
        Route::get('doctors/{doctor}', [DoctorController::class, 'show']);
        Route::get('chambers', [ChamberController::class, 'index']);
        Route::get('chambers/{chamber}', [ChamberController::class, 'show']);
        Route::get('doctors/{doctor}/schedule', [ScheduleController::class, 'show']);
        Route::get('serials', [SerialController::class, 'index']);
        Route::get('serials/{serial}', [SerialController::class, 'show']);
        Route::get('sessions', [SerialController::class, 'sessions']);
        Route::get('quote', [SerialController::class, 'quote']);
        Route::get('patients', [SerialController::class, 'patients'])->middleware('throttle:outpatient-writes');
        Route::get('queue/{chamber}', [QueueController::class, 'queue']);
        Route::get('my-patients', [QueueController::class, 'mine']);
        Route::get('serials/{serial}/consultation', [ConsultationController::class, 'show']);
        Route::get('serials/{serial}/prescription', [ConsultationController::class, 'prescription']);
        Route::get('medicines', [ConsultationController::class, 'medicines'])->middleware('throttle:outpatient-writes');
        Route::get('templates', [TemplateController::class, 'index']);

        Route::middleware('throttle:outpatient-writes')->group(function () {
            Route::post('doctors', [DoctorController::class, 'store']);
            Route::patch('doctors/{doctor}', [DoctorController::class, 'update']);
            Route::delete('doctors/{doctor}', [DoctorController::class, 'destroy']);
            Route::post('chambers', [ChamberController::class, 'store']);
            Route::patch('chambers/{chamber}', [ChamberController::class, 'update']);
            Route::delete('chambers/{chamber}', [ChamberController::class, 'destroy']);
            Route::put('doctors/{doctor}/schedule', [ScheduleController::class, 'update'])->name('outpatient.schedules.update');
            Route::post('serials', [SerialController::class, 'store'])->name('outpatient.serials.store');
            Route::post('serials/{serial}/status', [SerialController::class, 'move'])->name('outpatient.serials.move');
            Route::put('serials/{serial}/consultation', [ConsultationController::class, 'save'])->name('outpatient.consultations.save');
            Route::post('serials/{serial}/consultation/finish', [ConsultationController::class, 'finish'])->name('outpatient.consultations.finish');
            Route::post('serials/{serial}/consultation/amend', [ConsultationController::class, 'amend'])->name('outpatient.consultations.amend');
            Route::post('serials/{serial}/consultation/send-tests', [ConsultationController::class, 'sendTests']);
            Route::post('templates', [TemplateController::class, 'store'])->name('outpatient.templates.store');
            Route::patch('templates/{template}', [TemplateController::class, 'update'])->name('outpatient.templates.update');
            Route::delete('templates/{template}', [TemplateController::class, 'destroy']);
        });
    });

/*
| A patient's own prescriptions in the client's portal (B2B2C): portal members
| only, with the portal, Patients, Patient billing and Outpatient on.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:patients', 'module:patient_billing', 'module:outpatient'])
    ->prefix('portal/outpatient')
    ->group(function () {
        Route::get('prescriptions', PortalPrescriptionController::class);
    });
