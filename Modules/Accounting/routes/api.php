<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountController;
use Modules\Accounting\Http\Controllers\FiscalYearController;
use Modules\Accounting\Http\Controllers\JournalController;
use Modules\Accounting\Http\Controllers\JournalStepController;
use Modules\Accounting\Http\Controllers\PostingAccountController;
use Modules\Accounting\Http\Controllers\ReportController;
use Modules\Accounting\Http\Controllers\SetupController;

/*
| Accounting API (loaded by AccountingServiceProvider under /api, group
| "api"): a signed-in organization context, the module on there (403
| otherwise), the organization in the address keeping books (a company or
| personal workspace), and the permission checked in each controller.
*/

Route::middleware(['auth:sanctum', 'org', 'module:accounting'])
    ->prefix('organizations/{organization}/accounting')
    ->group(function () {
        Route::get('setup', [SetupController::class, 'show']);
        Route::get('accounts', [AccountController::class, 'index']);
        Route::get('fiscal-years', [FiscalYearController::class, 'index']);
        Route::get('posting-accounts', [PostingAccountController::class, 'index']);
        Route::get('journals', [JournalController::class, 'index']);
        Route::get('journals/{journal}', [JournalController::class, 'show']);
        // Unknown reports are refused by the controller, after the organization checks.
        Route::get('reports/{report}', ReportController::class)->middleware('throttle:accounting-reports');

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('setup', [SetupController::class, 'store']);
            Route::post('accounts', [AccountController::class, 'store']);
            Route::patch('accounts/{account}', [AccountController::class, 'update']);
            Route::post('fiscal-years', [FiscalYearController::class, 'store']);
            Route::post('periods/{period}/{step}', [FiscalYearController::class, 'period']);
            Route::put('posting-accounts/{key}', [PostingAccountController::class, 'update']);
            Route::post('journals', [JournalController::class, 'store']);
            Route::patch('journals/{journal}', [JournalController::class, 'update']);
            Route::delete('journals/{journal}', [JournalController::class, 'destroy']);
            Route::post('journals/{journal}/{step}', JournalStepController::class);
        });
    });
