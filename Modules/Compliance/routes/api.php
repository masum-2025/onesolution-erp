<?php

use Illuminate\Support\Facades\Route;
use Modules\Compliance\Http\Controllers\ImportController;
use Modules\Compliance\Http\Controllers\ListController;
use Modules\Compliance\Http\Controllers\RegisterController;
use Modules\Compliance\Http\Controllers\RenewalController;

/*
| Compliance API (loaded by ComplianceServiceProvider under /api, group "api"):
| a signed-in organization context, the module on there (403 otherwise), and
| the permission checked in each controller at the factory concerned.
*/

Route::middleware(['auth:sanctum', 'org', 'module:compliance'])
    ->prefix('organizations/{organization}/compliance')
    ->group(function () {
        Route::get('options', [RegisterController::class, 'options']);
        Route::get('register', [RegisterController::class, 'index']);
        Route::get('audits/{audit}', [RegisterController::class, 'show']);
        Route::get('lists/{kind}', [ListController::class, 'index']);
        Route::get('import/template', [ImportController::class, 'template']);
        // Renewals with the providers (CMP-2).
        Route::get('renewals', [RenewalController::class, 'index']);

        Route::middleware('throttle:compliance-writes')->group(function () {
            Route::post('audits', [RegisterController::class, 'store']);
            Route::patch('audits/{audit}', [RegisterController::class, 'update']);
            Route::post('audits/{audit}/dates', [RegisterController::class, 'dates']);
            Route::post('audits/{audit}/renewal', [RenewalController::class, 'store']);
            Route::post('renewals/{renewal}/cancel', [RenewalController::class, 'cancel']);
            Route::post('results/{result}/{step}', [RenewalController::class, 'decide']);
            // Literal before {item}.
            Route::post('lists/{kind}/defaults', [ListController::class, 'defaults']);
            Route::post('lists/{kind}', [ListController::class, 'store']);
            Route::patch('lists/{kind}/{item}', [ListController::class, 'update']);
        });
        Route::post('import', [ImportController::class, 'store'])->middleware('throttle:compliance-imports');
    });
