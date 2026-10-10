<?php

use Illuminate\Support\Facades\Route;
use Modules\EducationFees\Http\Controllers\BillingController;
use Modules\EducationFees\Http\Controllers\ConcessionController;
use Modules\EducationFees\Http\Controllers\FeeSetupController;

/*
| Student fees API (loaded by EducationFeesServiceProvider under /api, group
| "api"): a signed-in organization context, Education and fees on there
| (403 otherwise), and the permission checked in each controller at the unit
| in the address. Changes to money, discounts and fines: the sensitive limit.
*/

Route::middleware(['auth:sanctum', 'org', 'module:education', 'module:education_fees'])
    ->prefix('organizations/{organization}/fees')
    ->group(function () {
        Route::get('setup', [FeeSetupController::class, 'setup']);
        Route::get('structures', [FeeSetupController::class, 'structures']);
        Route::get('structures/{structure}', [FeeSetupController::class, 'showStructure']);
        Route::get('concessions', [ConcessionController::class, 'index']);
        Route::get('runs', [BillingController::class, 'runs']);
        Route::get('runs/{run}', [BillingController::class, 'showRun']);
        Route::get('bills', [BillingController::class, 'bills']);
        Route::get('bills/{bill}', [BillingController::class, 'showBill']);
        Route::get('students/{student}', [BillingController::class, 'student']);

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('heads', [FeeSetupController::class, 'storeHead']);
            Route::patch('heads/{head}', [FeeSetupController::class, 'updateHead']);
            Route::post('structures', [FeeSetupController::class, 'storeStructure']);
            Route::patch('structures/{structure}', [FeeSetupController::class, 'updateStructure']);
            Route::post('structures/{structure}/activate', [FeeSetupController::class, 'activateStructure']);
            Route::post('structures/{structure}/archive', [FeeSetupController::class, 'archiveStructure']);

            Route::post('concessions', [ConcessionController::class, 'store']);
            Route::post('concessions/{concession}/approve', [ConcessionController::class, 'approve']);
            Route::post('concessions/{concession}/reject', [ConcessionController::class, 'reject']);
            Route::post('concessions/{concession}/end', [ConcessionController::class, 'end']);

            Route::post('runs', [BillingController::class, 'storeRun']);
            Route::post('runs/{run}/refresh', [BillingController::class, 'refreshRun']);
            Route::post('runs/{run}/finalize', [BillingController::class, 'finalizeRun']);
            Route::post('runs/{run}/cancel', [BillingController::class, 'cancelRun']);
            Route::post('bills/{bill}/cancel', [BillingController::class, 'cancelBill']);
            Route::post('bills/{bill}/waive-fine', [BillingController::class, 'waiveFine']);
        });
    });
