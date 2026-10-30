<?php

use Illuminate\Support\Facades\Route;
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
        Route::get('templates', [TemplateController::class, 'index']);
        Route::get('templates/{template}', [TemplateController::class, 'show']);
        Route::get('', [ProjectController::class, 'index']);
        Route::get('{project}', [ProjectController::class, 'show']);
        Route::get('{project}/tasks/{task}/notes', [TaskController::class, 'notes']);

        Route::middleware('throttle:projects-writes')->group(function () {
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
            // Literal "tasks" above: this is start, hold, resume, finish, cancel or reopen.
            Route::post('{project}/{step}', [ProjectController::class, 'step']);
        });
    });
