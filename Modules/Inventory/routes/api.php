<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\CatalogController;
use Modules\Inventory\Http\Controllers\CountController;
use Modules\Inventory\Http\Controllers\DocumentController;
use Modules\Inventory\Http\Controllers\ReportController;
use Modules\Inventory\Http\Controllers\StockController;

/*
| Inventory API (loaded by InventoryServiceProvider under /api, group "api"):
| a signed-in organization context, the module on there (403 otherwise),
| and the permission checked in each controller (set-up at the company,
| documents and counts at the warehouse's unit).
*/

Route::middleware(['auth:sanctum', 'org', 'module:inventory'])
    ->prefix('organizations/{organization}/inventory')
    ->group(function () {
        foreach (['units', 'categories', 'warehouses', 'items'] as $kind) {
            Route::get($kind, [CatalogController::class, 'index']);
        }
        Route::get('lookup', [StockController::class, 'lookup']);
        Route::get('items/{item}', [StockController::class, 'item']);
        Route::get('stock', [StockController::class, 'index']);
        Route::get('moves', [StockController::class, 'moves']);
        Route::get('expiring', [StockController::class, 'expiring']);
        Route::get('documents', [DocumentController::class, 'index']);
        Route::get('documents/{document}', [DocumentController::class, 'show']);
        Route::get('counts', [CountController::class, 'index']);
        Route::get('counts/{count}', [CountController::class, 'show']);
        // Reports (valuation, reorder, slow, ledger): read in full, often exported, so limited.
        Route::get('reports/{report}', [ReportController::class, 'show'])->middleware('throttle:inventory-reports');

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            foreach (['units', 'categories', 'warehouses', 'items'] as $kind) {
                Route::post($kind, [CatalogController::class, 'store']);
                Route::patch("{$kind}/{id}", [CatalogController::class, 'update']);
            }
            Route::post('documents', [DocumentController::class, 'store']);
            Route::patch('documents/{document}', [DocumentController::class, 'update']);
            Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
            // Unknown steps are refused by the controller, after the organization checks.
            Route::post('documents/{document}/{step}', [DocumentController::class, 'step']);
            Route::post('counts', [CountController::class, 'store']);
            Route::patch('counts/{count}', [CountController::class, 'record']);
            Route::post('counts/{count}/{step}', [CountController::class, 'step']);
        });
    });
