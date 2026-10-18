<?php

use Illuminate\Support\Facades\Route;
use Modules\Zkteco\Http\Controllers\BridgeApiController;
use Modules\Zkteco\Http\Controllers\BridgeController;
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

        // Office bridges (an app hosted online reads devices through them).
        Route::get('bridges', [BridgeController::class, 'index']);
        Route::get('bridges/program', [BridgeController::class, 'program'])->middleware('throttle:zkteco-actions');
        Route::middleware('throttle:zkteco-writes')->group(function () {
            Route::post('bridges', [BridgeController::class, 'store']);
            Route::post('bridges/{bridge}/revoke', [BridgeController::class, 'revoke']);
        });
    });

/*
| What zkteco-bridge.exe on an office computer talks to: its token only
| (Authorization: Bearer zkb…), no session and no organization in the
| address; the token names the company and the bridge.
*/
Route::middleware('throttle:zkteco-bridge')->prefix('zkteco/bridge')->group(function () {
    Route::get('devices', [BridgeApiController::class, 'devices']);
    Route::post('devices/{device}/readings', [BridgeApiController::class, 'readings']);
    Route::post('devices/{device}/error', [BridgeApiController::class, 'error']);
});
