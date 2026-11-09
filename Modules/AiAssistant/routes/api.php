<?php

use Illuminate\Support\Facades\Route;
use Modules\AiAssistant\Http\Controllers\UsageController;

/*
| AI assistant API (loaded by AiAssistantServiceProvider under /api, group
| "api"): signed in, the module on (403 otherwise), permission checked in
| the controller at the company.
*/

Route::middleware(['auth:sanctum', 'org', 'module:ai_assistant'])
    ->prefix('organizations/{organization}/ai')
    ->group(function () {
        Route::get('usage', [UsageController::class, 'show']);
    });
