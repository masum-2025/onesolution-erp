<?php

use Illuminate\Support\Facades\Route;
use Modules\Pos\Http\Controllers\CustomerController;
use Modules\Pos\Http\Controllers\RegisterController;
use Modules\Pos\Http\Controllers\ReportController;
use Modules\Pos\Http\Controllers\SaleController;
use Modules\Pos\Http\Controllers\SessionController;

/*
| Point of sale API (loaded by PosServiceProvider under /api, group "api"):
| a signed-in organization context, POS and Inventory on there (403
| otherwise), and the permission checked in each controller at the
| counter's branch.
*/

Route::middleware(['auth:sanctum', 'org', 'module:inventory', 'module:pos'])
    ->prefix('organizations/{organization}/pos')
    ->group(function () {
        Route::get('registers', [RegisterController::class, 'index']);
        Route::get('registers/{register}/catalogue', [RegisterController::class, 'catalogue']);
        Route::get('sessions', [SessionController::class, 'index']);
        Route::get('sessions/{session}', [SessionController::class, 'show']);
        Route::get('sales', [SaleController::class, 'index']);
        Route::get('sales/{sale}', [SaleController::class, 'show']);
        Route::get('reports', ReportController::class)->middleware('throttle:pos-reports');

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('registers', [RegisterController::class, 'store']);
            Route::patch('registers/{register}', [RegisterController::class, 'update']);
            Route::post('registers/{register}/open', [SessionController::class, 'open']);
            Route::post('sessions/{session}/close', [SessionController::class, 'close']);
            Route::post('sessions/{session}/review', [SessionController::class, 'review']);
            Route::post('sales/{sale}/return', [SaleController::class, 'giveBack']);
        });
        // Selling is the counter's everyday work: its own, more generous limit.
        Route::post('sales', [SaleController::class, 'store'])->middleware('throttle:pos-sales');
        // A returning customer by mobile number (bounded like the sales).
        Route::get('customers', CustomerController::class)->middleware('throttle:pos-sales');
    });
