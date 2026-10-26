<?php

use Illuminate\Support\Facades\Route;
use Modules\Expenses\Http\Controllers\CategoryController;
use Modules\Expenses\Http\Controllers\ClaimController;
use Modules\Expenses\Http\Controllers\MeController;

/*
| Expenses API (loaded by ExpensesServiceProvider under /api, group "api"):
| a signed-in organization context, HRM and Expenses on there (403
| otherwise), and the permission checked in each controller at the unit
| concerned.
*/

Route::middleware(['auth:sanctum', 'org', 'module:hrm', 'module:expenses'])
    ->prefix('organizations/{organization}/expenses')
    ->group(function () {
        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('claims', [ClaimController::class, 'index']);
        Route::get('claims/{claim}', [ClaimController::class, 'show']);
        Route::get('me', [MeController::class, 'show']);
        Route::get('me/claims/{claim}', [MeController::class, 'claim']);

        // Receipts are read and kept: a few a minute (before me/claims/{claim}/{step}, which would take the path).
        Route::post('me/claims/{claim}/receipts', [MeController::class, 'addReceipt'])->middleware('throttle:expenses-uploads');

        Route::middleware('throttle:expenses-writes')->group(function () {
            Route::post('categories', [CategoryController::class, 'store']);
            Route::patch('categories/{category}', [CategoryController::class, 'update']);
            Route::post('categories/defaults', [CategoryController::class, 'defaults']);
            Route::post('claims/{claim}/pay', [ClaimController::class, 'pay']);
            Route::post('claims/{claim}/{step}', [ClaimController::class, 'decide']);
            Route::post('me/claims', [MeController::class, 'store']);
            Route::patch('me/claims/{claim}', [MeController::class, 'update']);
            Route::delete('me/claims/{claim}', [MeController::class, 'destroy']);
            Route::post('me/claims/{claim}/{step}', [MeController::class, 'step']);
            Route::delete('me/claims/{claim}/receipts/{receipt}', [MeController::class, 'removeReceipt']);
        });
    });
