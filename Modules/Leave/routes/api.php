<?php

use Illuminate\Support\Facades\Route;
use Modules\Leave\Http\Controllers\EncashmentController;
use Modules\Leave\Http\Controllers\MeController;
use Modules\Leave\Http\Controllers\PortalLeaveController;
use Modules\Leave\Http\Controllers\RequestController;
use Modules\Leave\Http\Controllers\TypeController;

/*
| Leave API (loaded by LeaveServiceProvider under /api, group "api"): a
| signed-in organization context, HRM and Leave on there (403 otherwise),
| and the permission checked in each controller at the unit concerned.
*/

Route::middleware(['auth:sanctum', 'org', 'module:hrm', 'module:leave'])
    ->prefix('organizations/{organization}/leave')
    ->group(function () {
        Route::get('types', [TypeController::class, 'index']);
        Route::get('requests', [RequestController::class, 'index']);
        Route::get('requests/{leave}', [RequestController::class, 'show']);
        Route::get('balances', [RequestController::class, 'balances']);
        Route::get('people', [RequestController::class, 'people']);
        Route::get('encashments', [EncashmentController::class, 'index']);
        Route::get('me', [MeController::class, 'show']);

        Route::middleware('throttle:leave-writes')->group(function () {
            Route::post('types', [TypeController::class, 'store']);
            Route::patch('types/{type}', [TypeController::class, 'update']);
            Route::post('types/defaults', [TypeController::class, 'defaults']);
            Route::post('requests', [RequestController::class, 'store']);
            Route::post('requests/{leave}/{step}', [RequestController::class, 'decide']);
            Route::post('balances/adjust', [RequestController::class, 'adjust']);
            Route::post('encashments', [EncashmentController::class, 'store']);
            Route::post('encashments/{encashment}/{step}', [EncashmentController::class, 'decide']);
            Route::post('me/requests', [MeController::class, 'ask']);
            Route::post('me/requests/{leave}/cancel', [MeController::class, 'cancel']);
        });
    });

/*
| An employee's own leave in the client's portal (B2B2C): portal members
| only, with the portal and Leave on.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:leave'])
    ->prefix('portal/leave')
    ->group(function () {
        Route::get('/', [PortalLeaveController::class, 'show']);
    });
