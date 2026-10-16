<?php

use Illuminate\Support\Facades\Route;
use Modules\FactoryErp\Http\Controllers\FloorController;
use Modules\FactoryErp\Http\Controllers\OrderController;
use Modules\FactoryErp\Http\Controllers\SetupController;
use Modules\FactoryErp\Http\Controllers\SewingController;
use Modules\FactoryErp\Http\Controllers\StyleController;

/*
| Factory ERP API (loaded by FactoryErpServiceProvider under /api, group
| "api"): a signed-in organization context, Factory ERP on there (403
| otherwise; it needs Inventory, Accounting and CRM), and the permission
| checked in each controller at the unit in the address.
*/

Route::middleware(['auth:sanctum', 'org', 'module:factory_erp'])
    ->prefix('organizations/{organization}/factory')
    ->group(function () {
        Route::get('setup', [SetupController::class, 'setup']);
        Route::get('buyers', [SetupController::class, 'buyers']);
        Route::get('fields', [SetupController::class, 'fields']);
        Route::get('styles', [StyleController::class, 'index']);
        Route::get('styles/{style}', [StyleController::class, 'show']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::get('orders/{order}/requirements', [OrderController::class, 'requirements']);
        Route::get('orders/{order}/issues', [FloorController::class, 'issues']);
        Route::get('orders/{order}/cuttings', [FloorController::class, 'cuttings']);
        Route::get('orders/{order}/progress', [FloorController::class, 'progress']);
        Route::get('orders/{order}/receipts', [FloorController::class, 'receipts']);
        Route::get('lines', [SewingController::class, 'lines']);
        Route::get('outputs', [SewingController::class, 'outputs']);

        Route::middleware('throttle:factory-writes')->group(function () {
            Route::post('buyers', [SetupController::class, 'addBuyer']);
            Route::post('fields', [SetupController::class, 'storeField']);
            Route::patch('fields/{field}', [SetupController::class, 'updateField']);
            Route::post('styles', [StyleController::class, 'store']);
            Route::patch('styles/{style}', [StyleController::class, 'update']);
            Route::put('styles/{style}/bom', [StyleController::class, 'saveBom']);
            Route::post('orders', [OrderController::class, 'store']);
            Route::patch('orders/{order}', [OrderController::class, 'update']);
            Route::post('orders/{order}/confirm', [OrderController::class, 'confirm']);
            Route::post('orders/{order}/start', [OrderController::class, 'start']);
            Route::post('orders/{order}/ship', [OrderController::class, 'ship']);
            Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
            Route::post('orders/{order}/issues', [FloorController::class, 'issue']);
            Route::post('orders/{order}/cuttings', [FloorController::class, 'cut']);
            Route::post('orders/{order}/cuttings/{cutting}/void', [FloorController::class, 'voidCutting']);
            Route::post('orders/{order}/receipts', [FloorController::class, 'receive']);
            Route::post('lines', [SewingController::class, 'storeLine']);
            Route::patch('lines/{line}', [SewingController::class, 'updateLine']);
            Route::post('outputs', [SewingController::class, 'storeOutput']);
            Route::post('line-days', [SewingController::class, 'saveDay']);
            Route::put('styles/{style}/operations', [StyleController::class, 'saveOperations']);
            Route::post('outputs/{output}/void', [SewingController::class, 'voidOutput']);
        });
    });
