<?php

use Illuminate\Support\Facades\Route;
use Modules\Attendance\Http\Controllers\AttendanceController;
use Modules\Attendance\Http\Controllers\MeController;
use Modules\Attendance\Http\Controllers\PortalAttendanceController;
use Modules\Attendance\Http\Controllers\ScheduleController;

/*
| Attendance API (loaded by AttendanceServiceProvider under /api, group
| "api"): a signed-in organization context, the module on there (403
| otherwise), and the permission checked in each controller at the unit of
| the employee concerned.
*/

Route::middleware(['auth:sanctum', 'org', 'module:attendance'])
    ->prefix('organizations/{organization}/attendance')
    ->group(function () {
        Route::get('shifts', [ScheduleController::class, 'shifts']);
        Route::get('holidays', [ScheduleController::class, 'holidays']);
        Route::get('rosters', [ScheduleController::class, 'rosters']);
        Route::get('days', [AttendanceController::class, 'days']);
        Route::get('punches', [AttendanceController::class, 'punches']);
        Route::get('corrections', [AttendanceController::class, 'corrections']);
        Route::get('me', [MeController::class, 'show']);
        Route::get('me/days', [MeController::class, 'days']);
        Route::get('me/corrections', [MeController::class, 'corrections']);

        // Checking in and out: a few a minute per person.
        Route::post('me/punch', [MeController::class, 'punch'])->middleware('throttle:attendance-punch');

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('shifts', [ScheduleController::class, 'storeShift']);
            Route::patch('shifts/{shift}', [ScheduleController::class, 'updateShift']);
            Route::post('holidays', [ScheduleController::class, 'storeHoliday']);
            Route::delete('holidays/{holiday}', [ScheduleController::class, 'destroyHoliday']);
            Route::post('rosters', [ScheduleController::class, 'assign']);
            Route::post('punches', [AttendanceController::class, 'writePunch']);
            Route::post('punches/{punch}/void', [AttendanceController::class, 'voidPunch']);
            Route::post('corrections', [AttendanceController::class, 'ask']);
            Route::post('corrections/{correction}/{step}', [AttendanceController::class, 'decide']);
            Route::post('me/corrections', [MeController::class, 'ask']);
        });
    });

/*
| An employee's own days in the client's portal (B2B2C): portal members
| only, with the portal and Attendance on.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:attendance'])
    ->prefix('portal/attendance')
    ->group(function () {
        Route::get('days', [PortalAttendanceController::class, 'days']);
    });
