<?php

use Illuminate\Support\Facades\Route;
use Modules\PatientBilling\Http\Controllers\ApprovalController;
use Modules\PatientBilling\Http\Controllers\BillController;
use Modules\PatientBilling\Http\Controllers\CashDayController;
use Modules\PatientBilling\Http\Controllers\MoneyController;
use Modules\PatientBilling\Http\Controllers\PanelController;
use Modules\PatientBilling\Http\Controllers\PortalBillController;
use Modules\PatientBilling\Http\Controllers\ServiceController;
use Modules\PatientBilling\Http\Controllers\SetupController;

/*
| Patient billing API: a signed-in organization context, Patients and
| Patient billing on there (403 otherwise), and the permission checked in
| each controller at the unit in the address. Money and writes are limited.
*/

Route::middleware(['auth:sanctum', 'org', 'module:patients', 'module:patient_billing'])
    ->prefix('organizations/{organization}/patient-billing')
    ->group(function () {
        Route::get('setup', SetupController::class);
        Route::get('services', [ServiceController::class, 'index']);
        Route::get('services/{service}', [ServiceController::class, 'show']);
        Route::get('panels', [PanelController::class, 'index']);
        Route::get('panels/{panel}', [PanelController::class, 'show']);
        Route::get('bills', [BillController::class, 'index']);
        Route::get('bills/{bill}', [BillController::class, 'show']);
        Route::get('bills/{bill}/receipt', [BillController::class, 'receipt']);
        Route::get('patients', [MoneyController::class, 'patients'])->middleware('throttle:patient-billing-search');
        Route::get('approvals', [ApprovalController::class, 'index']);
        Route::get('cash-days', [CashDayController::class, 'index']);

        Route::middleware('throttle:patient-billing-writes')->group(function () {
            Route::post('services', [ServiceController::class, 'store']);
            Route::patch('services/{service}', [ServiceController::class, 'update']);
            Route::delete('services/{service}', [ServiceController::class, 'destroy']);
            Route::post('panels', [PanelController::class, 'store']);
            Route::patch('panels/{panel}', [PanelController::class, 'update']);
            Route::delete('panels/{panel}', [PanelController::class, 'destroy']);

            Route::post('bills', [BillController::class, 'store']);
            Route::post('bills/{bill}/lines', [BillController::class, 'addLine'])->name('patient_billing.lines.add');
            Route::delete('bills/{bill}/lines/{line}', [BillController::class, 'voidLine']);
            Route::put('bills/{bill}/panel', [BillController::class, 'panel'])->name('patient_billing.bills.panel');
            Route::post('bills/{bill}/discount', [BillController::class, 'discount'])->name('patient_billing.bills.discount');
            Route::post('bills/{bill}/finalize', [BillController::class, 'finalize'])->name('patient_billing.bills.finalize');
            Route::post('bills/{bill}/void', [BillController::class, 'void'])->name('patient_billing.bills.void');
            Route::post('discount-requests/{discount}/decide', [ApprovalController::class, 'decide'])->name('patient_billing.discounts.decide');
            Route::post('cash-days/open', [CashDayController::class, 'open'])->name('patient_billing.days.open');
            Route::post('cash-days/{day}/close', [CashDayController::class, 'close'])->name('patient_billing.days.close');
            Route::post('cash-days/{day}/review', [CashDayController::class, 'review'])->name('patient_billing.days.review');
        });
        // Money: its own limit, separate from other writes (a busy counter).
        Route::middleware('throttle:patient-billing-money')->group(function () {
            Route::post('payments', [MoneyController::class, 'store']);
            Route::post('bills/{bill}/refund', [MoneyController::class, 'refund'])->name('patient_billing.bills.refund');
        });
    });

/*
| A patient's own bills in the client's portal (B2B2C): portal members only
| (the API refuses them everything else), with the portal, Patients and
| Patient billing on.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:patients', 'module:patient_billing'])
    ->prefix('portal/patient-billing')
    ->group(function () {
        Route::get('bills', PortalBillController::class);
    });
