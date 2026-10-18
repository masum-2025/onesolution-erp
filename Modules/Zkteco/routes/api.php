<?php

use Illuminate\Support\Facades\Route;
use Modules\Zkteco\Http\Controllers\DeviceController;

/*
| ZKTeco terminals API (loaded by ZktecoServiceProvider under /api, group
| "api"): a signed-in organization context, HRM, attendance and ZKTeco on
| there (403 otherwise), and the permission checked in each controller at
| the unit in the address.
*/

Route::middleware(['auth:sanctum', 'org', 'module:hrm', 'module:attendance', 'module:zkteco'])
    ->prefix('organizations/{organization}/zkteco')
    ->group(function () {
        Route::get('setup', [DeviceController::class, 'setup']);
        Route::get('devices', [DeviceController::class, 'index']);
        Route::get('devices/{device}/users', [DeviceController::class, 'users']);

        Route::middleware('throttle:zkteco-writes')->group(function () {
            Route::post('devices', [DeviceController::class, 'store']);
            Route::patch('devices/{device}', [DeviceController::class, 'update']);
            Route::delete('devices/{device}', [DeviceController::class, 'destroy']);
            Route::patch('devices/{device}/users/{user}', [DeviceController::class, 'match']);
            Route::patch('devices/{device}/users', [DeviceController::class, 'matchMany']);
        });
        // Each talks to the terminal: few a minute.
        Route::middleware('throttle:zkteco-actions')->group(function () {
            Route::post('devices/{device}/test', [DeviceController::class, 'test']);
            Route::post('devices/{device}/sync', [DeviceController::class, 'sync']);
        });
    });
