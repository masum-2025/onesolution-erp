<?php

use Illuminate\Support\Facades\Route;
use Modules\OnlineOrders\Http\Controllers\CourierController;
use Modules\OnlineOrders\Http\Controllers\CourierWebhookController;
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
        // SC-5b: the company's courier accounts (online_orders.couriers).
        Route::get('couriers', [CourierController::class, 'index']);

        Route::middleware('throttle:online-orders-writes')->group(function () {
            Route::post('orders', [OrderController::class, 'store']);
            Route::patch('orders/{order}', [OrderController::class, 'update']);
            Route::post('orders/{order}/payments', [OrderController::class, 'pay']);
            Route::post('orders/{order}/pay-link', [OrderController::class, 'payLink']);
            Route::post('orders/{order}/book', [OrderController::class, 'book']);
            Route::post('orders/{order}/courier-status', [OrderController::class, 'courierStatus']);
            Route::post('orders/{order}/{step}', [OrderController::class, 'step'])->whereIn('step', ['confirm', 'pack', 'ship', 'deliver', 'cancel', 'return']);
            Route::post('couriers', [CourierController::class, 'store']);
            Route::patch('couriers/{courier}', [CourierController::class, 'update']);
        });
    });

// SC-5b: a courier's notices about parcels. No sign-in: the account's own Bearer token is the proof (401 otherwise).
Route::post('couriers/{organization}/{courier}/webhook', CourierWebhookController::class)
    ->whereUlid(['organization', 'courier'])
    ->middleware('throttle:online-orders-courier-notices')
    ->name('online_orders.courier_webhook');
