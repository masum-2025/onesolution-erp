<?php

use Illuminate\Support\Facades\Route;
use Modules\Appraisals\Http\Controllers\CompetencyController;
use Modules\Appraisals\Http\Controllers\CycleController;
use Modules\Appraisals\Http\Controllers\ReviewController;

/*
| Appraisals API (loaded by AppraisalsServiceProvider under /api, group
| "api"): a signed-in organization context, HRM and Appraisals on there
| (403 otherwise), and the permission checked in each controller at the
| unit concerned.
*/

Route::middleware(['auth:sanctum', 'org', 'module:hrm', 'module:appraisals'])
    ->prefix('organizations/{organization}/appraisals')
    ->group(function () {
        Route::get('options', [CycleController::class, 'options']);
        Route::get('competencies', [CompetencyController::class, 'index']);
        Route::get('cycles', [CycleController::class, 'index']);
        Route::get('cycles/{cycle}', [CycleController::class, 'show']);
        Route::get('me', [ReviewController::class, 'mine']);
        Route::get('team', [ReviewController::class, 'team']);
        Route::get('reviews/{review}', [ReviewController::class, 'show']);

        Route::middleware('throttle:appraisals-writes')->group(function () {
            Route::post('competencies', [CompetencyController::class, 'store']);
            Route::post('competencies/defaults', [CompetencyController::class, 'defaults']);
            Route::patch('competencies/{competency}', [CompetencyController::class, 'update']);
            Route::post('cycles', [CycleController::class, 'store']);
            Route::patch('cycles/{cycle}', [CycleController::class, 'update']);
            Route::post('cycles/{cycle}/{step}', [CycleController::class, 'step']);
            Route::put('reviews/{review}/goals', [ReviewController::class, 'goals']);
            Route::put('reviews/{review}/ratings', [ReviewController::class, 'ratings']);
            Route::post('reviews/{review}/{step}', [ReviewController::class, 'step']);
        });
    });
