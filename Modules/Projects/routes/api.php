<?php

use Illuminate\Support\Facades\Route;
use Modules\Projects\Http\Controllers\InboxController;
use Modules\Projects\Http\Controllers\MoneyController;
use Modules\Projects\Http\Controllers\ProjectController;
use Modules\Projects\Http\Controllers\TaskController;
use Modules\Projects\Http\Controllers\TemplateController;

/*
| Projects API (loaded by ProjectsServiceProvider under /api, group "api"):
| a signed-in organization context, the module on there (403 otherwise), and
| the permission checked in each controller at the unit concerned.
*/

Route::middleware(['auth:sanctum', 'org', 'module:projects'])
    ->prefix('organizations/{organization}/projects')
    ->group(function () {
        // Literal paths before {project}.
        Route::get('options', [ProjectController::class, 'options']);
        Route::get('clients', [ProjectController::class, 'clients']);
        Route::get('my-tasks', [TaskController::class, 'mine']);
        // Renewal requests from connected clients (optional, over a business connection).
        Route::get('requests', [InboxController::class, 'index']);
        Route::get('templates', [TemplateController::class, 'index']);
        Route::get('templates/{template}', [TemplateController::class, 'show']);
        Route::get('', [ProjectController::class, 'index']);
        Route::get('{project}', [ProjectController::class, 'show']);
        Route::get('{project}/tasks/{task}/notes', [TaskController::class, 'notes']);
        // The project's quotes (CRM) and invoices (Accounting), PRJ-2.
        Route::get('{project}/money', [MoneyController::class, 'show']);

        Route::middleware('throttle:projects-writes')->group(function () {
            Route::post('clients', [ProjectController::class, 'addClient']);
            Route::post('requests/{incoming}/accept', [InboxController::class, 'accept']);
            Route::post('requests/{incoming}/decline', [InboxController::class, 'decline']);
            Route::post('templates', [TemplateController::class, 'store']);
            Route::patch('templates/{template}', [TemplateController::class, 'update']);
            Route::post('', [ProjectController::class, 'store']);
            Route::patch('{project}', [ProjectController::class, 'update']);
            Route::post('{project}/tasks', [TaskController::class, 'store']);
            Route::post('{project}/tasks/order', [TaskController::class, 'order']);
            Route::patch('{project}/tasks/{task}', [TaskController::class, 'update']);
            Route::post('{project}/tasks/{task}/status', [TaskController::class, 'status']);
            Route::put('{project}/tasks/{task}/people', [TaskController::class, 'people']);
            Route::post('{project}/tasks/{task}/notes', [TaskController::class, 'addNote']);
            Route::post('{project}/quotes', [MoneyController::class, 'quote'])->name('projects.quote');
            Route::post('{project}/quotes/{quote}/send', [MoneyController::class, 'sendQuote']);
            Route::post('{project}/invoices', [MoneyController::class, 'invoice'])->name('projects.invoice');
            Route::post('{project}/client/crm', [MoneyController::class, 'clientToCrm']);
            Route::post('{project}/result', [InboxController::class, 'result']);
            // Literal "tasks" above: this is start, hold, resume, finish, cancel or reopen.
            Route::post('{project}/{step}', [ProjectController::class, 'step']);
        });
    });
