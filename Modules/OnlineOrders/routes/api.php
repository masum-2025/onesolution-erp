<?php

use Illuminate\Support\Facades\Route;
use Modules\OnlineOrders\Http\Controllers\OrderController;

/*
| Online orders API (loaded by OnlineOrdersServiceProvider under /api, group
| "api"): a signed-in organization context, Inventory and Online orders on
| there (403 otherwise), and the permission checked in the controller at
| the order's branch.
*/

Route::middleware(['auth:sanctum', 'org', 'module:inventory', 'module:online_orders'])
    ->prefix('organizations/{organization}/online-orders')
    ->group(function () {
        Route::get('options', [OrderController::class, 'options']);
        Route::get('items', [OrderController::class, 'items'])->middleware('throttle:online-orders-writes');
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);

        Route::middleware('throttle:online-orders-writes')->group(function () {
            Route::post('orders', [OrderController::class, 'store']);
            Route::patch('orders/{order}', [OrderController::class, 'update']);
            Route::post('orders/{order}/payments', [OrderController::class, 'pay']);
            Route::post('orders/{order}/{step}', [OrderController::class, 'step'])->whereIn('step', ['confirm', 'pack', 'ship', 'deliver', 'cancel', 'return']);
        });
    });
