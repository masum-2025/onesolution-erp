<?php

use Illuminate\Support\Facades\Route;
use Modules\Crm\Http\Controllers\ActivityController;
use Modules\Crm\Http\Controllers\ContactController;
use Modules\Crm\Http\Controllers\DealController;
use Modules\Crm\Http\Controllers\QuoteController;
use Modules\Crm\Http\Controllers\SettingsController;
use Modules\Crm\Http\Controllers\SetupController;

/*
| CRM API (loaded by CrmServiceProvider under /api, group "api"): a signed-in
| organization context, CRM on there (403 otherwise), and the permission
| checked in each controller at the unit in the address.
*/

Route::middleware(['auth:sanctum', 'org', 'module:crm'])
    ->prefix('organizations/{organization}/crm')
    ->group(function () {
        Route::get('setup', SetupController::class);
        Route::get('fields', [SettingsController::class, 'fields']);
        Route::get('contacts', [ContactController::class, 'index']);
        Route::get('contacts/export', [ContactController::class, 'export'])->middleware('throttle:crm-export');
        Route::get('contacts/{contact}', [ContactController::class, 'show']);
        Route::get('deals', [DealController::class, 'index']);
        Route::get('activities', [ActivityController::class, 'index']);
        Route::get('quotes', [QuoteController::class, 'index']);
        Route::get('quotes/{quote}', [QuoteController::class, 'show']);

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('contacts', [ContactController::class, 'store']);
            Route::patch('contacts/{contact}', [ContactController::class, 'update']);
            Route::post('contacts/{contact}/anonymize', [ContactController::class, 'anonymize']);
            Route::post('contacts/{contact}/customer', [ContactController::class, 'makeCustomer']);
            Route::post('deals', [DealController::class, 'store']);
            Route::patch('deals/{deal}', [DealController::class, 'update']);
            Route::post('deals/{deal}/{step}', [DealController::class, 'move']);
            Route::post('activities', [ActivityController::class, 'store']);
            Route::patch('activities/{activity}', [ActivityController::class, 'update']);
            Route::post('quotes', [QuoteController::class, 'store']);
            Route::patch('quotes/{quote}', [QuoteController::class, 'update']);
            Route::delete('quotes/{quote}', [QuoteController::class, 'destroy']);
            // Unknown steps are refused by the controller, after the organization checks.
            Route::post('quotes/{quote}/{step}', [QuoteController::class, 'step']);
            Route::post('pipelines', [SettingsController::class, 'storePipeline']);
            Route::patch('pipelines/{pipeline}', [SettingsController::class, 'updatePipeline']);
            Route::post('pipelines/{pipeline}/stages', [SettingsController::class, 'storeStage']);
            Route::patch('pipelines/{pipeline}/stages/{stage}', [SettingsController::class, 'updateStage']);
            Route::post('fields', [SettingsController::class, 'storeField']);
            Route::patch('fields/{field}', [SettingsController::class, 'updateField']);
        });
        Route::post('contacts/import', [ContactController::class, 'import'])->middleware('throttle:crm-import');
    });
