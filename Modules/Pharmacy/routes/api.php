<?php

use Illuminate\Support\Facades\Route;
use Modules\Pharmacy\Http\Controllers\ControlledController;
use Modules\Pharmacy\Http\Controllers\GenericController;
use Modules\Pharmacy\Http\Controllers\ManufacturerController;
use Modules\Pharmacy\Http\Controllers\MedicineController;
use Modules\Pharmacy\Http\Controllers\PurchaseController;
use Modules\Pharmacy\Http\Controllers\ReportController;
use Modules\Pharmacy\Http\Controllers\SaleController;
use Modules\Pharmacy\Http\Controllers\SetupController;
use Modules\Pharmacy\Http\Controllers\StockController;
use Modules\Pharmacy\Http\Controllers\SupplierReturnController;

/*
| Pharmacy API: a signed-in organization context, Inventory, POS and Pharmacy
| on there (403 otherwise), and the permission checked in each controller at
| the unit in the address (selling: at the counter's branch).
*/

Route::middleware(['auth:sanctum', 'org', 'module:inventory', 'module:pos', 'module:pharmacy'])
    ->prefix('organizations/{organization}/pharmacy')
    ->group(function () {
        Route::get('setup', SetupController::class);
        Route::get('generics', [GenericController::class, 'index']);
        Route::get('generics/{generic}', [GenericController::class, 'show']);
        Route::get('manufacturers', [ManufacturerController::class, 'index']);
        Route::get('manufacturers/{manufacturer}', [ManufacturerController::class, 'show']);
        Route::get('medicines', [MedicineController::class, 'index']);
        Route::get('medicines/{medicine}', [MedicineController::class, 'show']);
        Route::get('medicines/{medicine}/substitutes', [MedicineController::class, 'substitutes']);
        Route::get('counter/{register}', [SaleController::class, 'counter']);
        Route::get('counter/{register}/prescriptions', [SaleController::class, 'prescriptions'])->middleware('throttle:pharmacy-reports');
        Route::get('counter/{register}/prescriptions/{consultation}', [SaleController::class, 'prescription'])->middleware('throttle:pharmacy-reports');
        Route::get('sales', [SaleController::class, 'index']);
        Route::get('stock', StockController::class);
        Route::get('purchases', [PurchaseController::class, 'index']);
        Route::get('supplier-returns', [SupplierReturnController::class, 'index']);
        Route::get('controlled-register', ControlledController::class)->middleware('throttle:pharmacy-reports');
        Route::get('reports', ReportController::class)->middleware('throttle:pharmacy-reports');

        Route::middleware('throttle:pharmacy-writes')->group(function () {
            Route::post('generics', [GenericController::class, 'store']);
            Route::patch('generics/{generic}', [GenericController::class, 'update']);
            Route::delete('generics/{generic}', [GenericController::class, 'destroy']);
            Route::post('manufacturers', [ManufacturerController::class, 'store']);
            Route::patch('manufacturers/{manufacturer}', [ManufacturerController::class, 'update']);
            Route::delete('manufacturers/{manufacturer}', [ManufacturerController::class, 'destroy']);
            Route::post('medicines', [MedicineController::class, 'store']);
            Route::patch('medicines/{medicine}', [MedicineController::class, 'update']);
            Route::post('purchases', [PurchaseController::class, 'store']);
            Route::post('supplier-returns', [SupplierReturnController::class, 'store']);
            Route::post('supplier-returns/{return}/approve', [SupplierReturnController::class, 'approve']);
            Route::post('supplier-returns/{return}/reject', [SupplierReturnController::class, 'reject']);
        });
        // Selling is the counter's everyday work: POS's own, more generous limit.
        Route::post('sales', [SaleController::class, 'store'])->middleware('throttle:pos-sales');
    });
