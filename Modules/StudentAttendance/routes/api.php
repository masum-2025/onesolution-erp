<?php

use Illuminate\Support\Facades\Route;
use Modules\StudentAttendance\Http\Controllers\CalendarController;
use Modules\StudentAttendance\Http\Controllers\LedgerController;
use Modules\StudentAttendance\Http\Controllers\SheetController;

/*
| Students' attendance API (loaded by StudentAttendanceServiceProvider under
| /api, group "api"): a signed-in organization context, Education and
| attendance on there (403 otherwise), and the permission checked in each
| controller at the unit in the address.
*/

Route::middleware(['auth:sanctum', 'org', 'module:education', 'module:student_attendance'])
    ->prefix('organizations/{organization}/student-attendance')
    ->group(function () {
        Route::get('setup', [SheetController::class, 'setup']);
        Route::get('sheets', [SheetController::class, 'index']);
        Route::get('sheets/{section}/{date}', [SheetController::class, 'show']);
        Route::get('periods', [CalendarController::class, 'periods']);
        Route::get('holidays', [CalendarController::class, 'holidays']);
        Route::get('students/{student}', [LedgerController::class, 'student']);
        // The month's register, also as CSV.
        Route::get('register', [LedgerController::class, 'register'])->middleware('throttle:student-attendance-exports');

        Route::middleware('throttle:student-attendance-writes')->group(function () {
            Route::put('sheets/{section}/{date}', [SheetController::class, 'save']);
            Route::post('periods', [CalendarController::class, 'storePeriod']);
            Route::patch('periods/{period}', [CalendarController::class, 'updatePeriod']);
            Route::delete('periods/{period}', [CalendarController::class, 'destroyPeriod']);
            Route::post('holidays', [CalendarController::class, 'storeHoliday']);
            Route::delete('holidays/{holiday}', [CalendarController::class, 'destroyHoliday']);
        });
    });
