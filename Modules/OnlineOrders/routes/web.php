<?php

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\OnlineOrders\Http\Controllers\PayController;

/*
| SC-5a: an order's payment link "/pay/orders/{company}/{order}" (for the
| customer, no sign-in; no session or cookie is started). The signature
| checked in the controller is the proof; limited per address.
*/

$public = [StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, AddQueuedCookiesToResponse::class];
$ulid = '[0-9A-Za-z]{26}';

Route::get('pay/orders/{organization}/{order}', [PayController::class, 'show'])
    ->where(['organization' => $ulid, 'order' => $ulid])->middleware('throttle:online-orders-pay')->withoutMiddleware($public)->name('online_orders.pay');
Route::post('pay/orders/{organization}/{order}', [PayController::class, 'start'])
    ->where(['organization' => $ulid, 'order' => $ulid])->middleware('throttle:online-orders-pay')->withoutMiddleware($public)->name('online_orders.pay.start');
