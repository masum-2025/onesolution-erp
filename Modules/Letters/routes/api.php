<?php

use Illuminate\Support\Facades\Route;
use Modules\Letters\Http\Controllers\LetterController;
use Modules\Letters\Http\Controllers\PortalLetterController;
use Modules\Letters\Http\Controllers\TemplateController;

/*
| Letters API (loaded by LettersServiceProvider under /api, group "api"): a
| signed-in organization context, HRM and Letters on there (403 otherwise),
| and the permission checked in each controller at the unit concerned.
*/

Route::middleware(['auth:sanctum', 'org', 'module:hrm', 'module:letters'])
    ->prefix('organizations/{organization}/letters')
    ->group(function () {
        Route::get('options', [TemplateController::class, 'options']);
        Route::get('templates', [TemplateController::class, 'index']);
        Route::get('templates/{template}', [TemplateController::class, 'show']);
        Route::get('assets', [TemplateController::class, 'assets']);
        Route::get('employees', [LetterController::class, 'employees']);
        Route::get('issued', [LetterController::class, 'index']);
        Route::get('issued/{letter}', [LetterController::class, 'show']);
        Route::get('me', [LetterController::class, 'mine']);
        Route::get('me/{letter}', [LetterController::class, 'mineShow']);

        Route::middleware('throttle:letters-writes')->group(function () {
            Route::post('templates', [TemplateController::class, 'store']);
            Route::post('templates/presets/{preset}', [TemplateController::class, 'preset']);
            Route::patch('templates/{template}', [TemplateController::class, 'update']);
            Route::post('templates/{template}/preview', [TemplateController::class, 'preview']);
            Route::post('assets', [TemplateController::class, 'storeAsset']);
            Route::patch('assets/{asset}', [TemplateController::class, 'updateAsset']);
            Route::post('issued/{letter}/revoke', [LetterController::class, 'revoke']);
        });
        // Issuing to many people at once is limited on its own.
        Route::post('issued', [LetterController::class, 'store'])->middleware('throttle:letters-issue');
    });

/*
| An employee's own letters in the client's portal (B2B2C): portal members
| only, with the portal, HRM and Letters on.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:hrm', 'module:letters'])
    ->prefix('portal/letters')
    ->group(function () {
        Route::get('/', [PortalLetterController::class, 'index']);
        Route::get('{letter}', [PortalLetterController::class, 'show']);
    });
