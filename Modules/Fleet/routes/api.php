<?php

use Illuminate\Support\Facades\Route;
use Modules\Fleet\Http\Controllers\SetupController;
use Modules\Fleet\Http\Controllers\TripController;
use Modules\Fleet\Http\Controllers\VehicleController;

/*
| Fleet API (made by make:erp-module): a signed-in organization context, the module
| on there (403 otherwise), and the permission checked in each controller at the unit in the address.
*/

Route::middleware(['auth:sanctum', 'org', 'module:fleet'])
    ->prefix('organizations/{organization}/fleet')
    ->group(function () {
        Route::get('setup', SetupController::class);
        Route::get('vehicles', [VehicleController::class, 'index']);
        Route::get('vehicles/{vehicle}', [VehicleController::class, 'show']);
        Route::get('trips', [TripController::class, 'index']);
        Route::get('trips/{trip}', [TripController::class, 'show']);

        Route::middleware('throttle:fleet-writes')->group(function () {
            Route::post('vehicles', [VehicleController::class, 'store']);
            Route::patch('vehicles/{vehicle}', [VehicleController::class, 'update']);
            Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy']);
            Route::post('trips', [TripController::class, 'store']);
            Route::patch('trips/{trip}', [TripController::class, 'update']);
            Route::delete('trips/{trip}', [TripController::class, 'destroy']);
        });
    });
