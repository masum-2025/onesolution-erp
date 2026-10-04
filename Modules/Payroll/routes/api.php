<?php

use Illuminate\Support\Facades\Route;
use Modules\Payroll\Http\Controllers\MeController;
use Modules\Payroll\Http\Controllers\PortalSlipController;
use Modules\Payroll\Http\Controllers\RunController;
use Modules\Payroll\Http\Controllers\SetupController;

/*
| Payroll API (loaded by PayrollServiceProvider under /api, group "api"): a
| signed-in organization context, the module on there (403 otherwise), and
| the permission checked in each controller (runs at the company, salaries
| at the employee's unit).
*/

Route::middleware(['auth:sanctum', 'org', 'module:payroll'])
    ->prefix('organizations/{organization}/payroll')
    ->group(function () {
        Route::get('components', [SetupController::class, 'components']);
        Route::get('structures', [SetupController::class, 'structures']);
        Route::get('employees', [SetupController::class, 'employees']);
        Route::get('employees/{employee}', [SetupController::class, 'employee']);
        Route::get('runs', [RunController::class, 'index']);
        Route::get('runs/{run}', [RunController::class, 'show']);
        Route::get('runs/{run}/slips/{slip}', [RunController::class, 'slip']);
        Route::get('me/slips', [MeController::class, 'slips']);
        Route::get('me/slips/{slip}', [MeController::class, 'slip']);

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('components', [SetupController::class, 'storeComponent']);
            Route::patch('components/{component}', [SetupController::class, 'updateComponent']);
            Route::post('structures', [SetupController::class, 'storeStructure']);
            Route::patch('structures/{structure}', [SetupController::class, 'updateStructure']);
            Route::post('employees/{employee}/salary', [SetupController::class, 'setSalary']);
            Route::put('employees/{employee}/payment', [SetupController::class, 'setPayment']);
            Route::post('runs', [RunController::class, 'store']);
            Route::delete('runs/{run}', [RunController::class, 'destroy']);
            Route::post('runs/{run}/adjustments', [RunController::class, 'adjust']);
            Route::delete('runs/{run}/adjustments/{adjustment}', [RunController::class, 'removeAdjustment']);
            // Unknown steps are refused by the controller, after the organization checks.
            Route::post('runs/{run}/{step}', [RunController::class, 'step']);

            // Full account numbers: a recent second step, a few a minute.
            Route::get('runs/{run}/bank-file', [RunController::class, 'bankFile'])->middleware(['two_factor.recent', 'throttle:payroll-bank-file']);
        });
    });

/*
| An employee's own payslips in the client's portal (B2B2C): portal
| members linked to their employee record, with the portal and Payroll on.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:payroll'])
    ->prefix('portal/payroll')
    ->group(function () {
        Route::get('slips', [PortalSlipController::class, 'index']);
        Route::get('slips/{slip}', [PortalSlipController::class, 'show']);
    });
