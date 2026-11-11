<?php

use Illuminate\Support\Facades\Route;
use Modules\Outpatient\Http\Controllers\ChamberController;
use Modules\Outpatient\Http\Controllers\DoctorController;
use Modules\Outpatient\Http\Controllers\QueueController;
use Modules\Outpatient\Http\Controllers\ScheduleController;
use Modules\Outpatient\Http\Controllers\SerialController;
use Modules\Outpatient\Http\Controllers\SetupController;

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
        });
    });
