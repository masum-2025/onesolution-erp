<?php

use Illuminate\Support\Facades\Route;
use Modules\Diagnostics\Http\Controllers\OrderController;
use Modules\Diagnostics\Http\Controllers\PortalReportController;
use Modules\Diagnostics\Http\Controllers\ReferrerController;
use Modules\Diagnostics\Http\Controllers\ResultController;
use Modules\Diagnostics\Http\Controllers\SetupController;
use Modules\Diagnostics\Http\Controllers\TestController;
use Modules\Diagnostics\Http\Controllers\TestSetupController;

/*
| Diagnostics API: a signed-in organization context, Patients, Patient
| billing and Diagnostics on there (403 otherwise), and the permission
| checked in each controller at the unit in the address. Writes are limited.
*/

Route::middleware(['auth:sanctum', 'org', 'module:patients', 'module:patient_billing', 'module:diagnostics'])
    ->prefix('organizations/{organization}/diagnostics')
    ->group(function () {
        Route::get('setup', SetupController::class);
        Route::get('tests', [TestController::class, 'index']);
        Route::get('tests/{test}', [TestController::class, 'show']);
        Route::get('tests/{test}/parameters', [TestSetupController::class, 'show']);
        Route::get('referrers', [ReferrerController::class, 'index']);
        Route::get('referrers/{referrer}', [ReferrerController::class, 'show']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::get('orders/{order}/report', [OrderController::class, 'report']);
        Route::get('samples/{barcode}', [OrderController::class, 'bySample']);
        Route::get('patients', [OrderController::class, 'patients'])->middleware('throttle:diagnostics-writes');

        Route::middleware('throttle:diagnostics-writes')->group(function () {
            Route::post('tests', [TestController::class, 'store']);
            Route::patch('tests/{test}', [TestController::class, 'update']);
            Route::delete('tests/{test}', [TestController::class, 'destroy']);
            Route::put('tests/{test}/parameters', [TestSetupController::class, 'update']);
            Route::post('referrers', [ReferrerController::class, 'store']);
            Route::patch('referrers/{referrer}', [ReferrerController::class, 'update']);
            Route::delete('referrers/{referrer}', [ReferrerController::class, 'destroy']);

            Route::post('orders', [OrderController::class, 'store']);
            Route::post('orders/{order}/collect', [OrderController::class, 'collect']);
            Route::post('orders/{order}/deliver', [OrderController::class, 'deliver'])->name('diagnostics.orders.deliver');
            Route::post('samples/{sample}/reject', [OrderController::class, 'rejectSample'])->name('diagnostics.samples.reject');
            Route::put('order-tests/{test}/results', [ResultController::class, 'save'])->name('diagnostics.results.save');
            Route::post('order-tests/{test}/verify', [ResultController::class, 'verify'])->name('diagnostics.results.verify');
            Route::post('order-tests/{test}/send-back', [ResultController::class, 'sendBack'])->name('diagnostics.results.send_back');
        });
    });

/*
| A patient's own lab reports in the client's portal (B2B2C): portal members
| only, with the portal, Patients, Patient billing and Diagnostics on.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:patients', 'module:patient_billing', 'module:diagnostics'])
    ->prefix('portal/diagnostics')
    ->group(function () {
        Route::get('reports', PortalReportController::class);
    });
