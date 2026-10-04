<?php

use Illuminate\Support\Facades\Route;
use Modules\Hrm\Http\Controllers\CustomFieldController;
use Modules\Hrm\Http\Controllers\EmployeeController;
use Modules\Hrm\Http\Controllers\EmployeeDocumentController;
use Modules\Hrm\Http\Controllers\EmployeeImportController;
use Modules\Hrm\Http\Controllers\EmploymentStepController;
use Modules\Hrm\Http\Controllers\OrgChartController;
use Modules\Hrm\Http\Controllers\PositionController;

/*
| HRM API (loaded by HrmServiceProvider under /api, group "api"): a signed-in
| organization context, the module on at that organization (403 otherwise),
| and the permission checked in each controller at the employee's unit.
*/

Route::middleware(['auth:sanctum', 'org', 'module:hrm'])
    ->prefix('organizations/{organization}/hrm')
    ->group(function () {
        Route::get('form-options', [EmployeeController::class, 'formOptions']);
        Route::get('positions', [PositionController::class, 'index']);
        Route::get('org-chart', OrgChartController::class);
        Route::get('custom-fields', [CustomFieldController::class, 'index']);
        Route::get('imports/columns', [EmployeeImportController::class, 'columns']);
        Route::get('imports', [EmployeeImportController::class, 'index']);
        Route::get('imports/{import}', [EmployeeImportController::class, 'show']);
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::get('employees/{employee}', [EmployeeController::class, 'show']);
        Route::get('employees/{employee}/history', [EmployeeController::class, 'history']);
        Route::get('employees/{employee}/logins', [EmployeeController::class, 'logins']);
        Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'index']);
        Route::get('employees/{employee}/documents/{document}/link', [EmployeeDocumentController::class, 'link']);

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('positions', [PositionController::class, 'store']);
            Route::patch('positions/{position}', [PositionController::class, 'update']);
            Route::post('custom-fields', [CustomFieldController::class, 'store']);
            Route::patch('custom-fields/{field}', [CustomFieldController::class, 'update']);
            Route::post('imports', [EmployeeImportController::class, 'store']);
            Route::post('imports/{import}/start', [EmployeeImportController::class, 'start']);
            Route::delete('imports/{import}', [EmployeeImportController::class, 'destroy']);
            Route::post('employees', [EmployeeController::class, 'store']);
            Route::patch('employees/{employee}', [EmployeeController::class, 'update']);
            Route::put('employees/{employee}/login', [EmployeeController::class, 'login']);
            // Unknown steps are refused by the controller, after the organization checks.
            Route::post('employees/{employee}/steps/{step}', EmploymentStepController::class);
            Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store']);
            Route::delete('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy']);

            // Full national and tax ids: a recent second step for people who have one.
            Route::get('employees/{employee}/sensitive', [EmployeeController::class, 'sensitive'])->middleware('two_factor.recent');
        });
    });
