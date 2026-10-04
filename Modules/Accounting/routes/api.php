<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountController;
use Modules\Accounting\Http\Controllers\BankController;
use Modules\Accounting\Http\Controllers\DocumentController;
use Modules\Accounting\Http\Controllers\DocumentStepController;
use Modules\Accounting\Http\Controllers\FiscalYearController;
use Modules\Accounting\Http\Controllers\JournalController;
use Modules\Accounting\Http\Controllers\JournalStepController;
use Modules\Accounting\Http\Controllers\OpeningController;
use Modules\Accounting\Http\Controllers\PartyController;
use Modules\Accounting\Http\Controllers\PortalInvoiceController;
use Modules\Accounting\Http\Controllers\PostingAccountController;
use Modules\Accounting\Http\Controllers\ReportController;
use Modules\Accounting\Http\Controllers\SettlementController;
use Modules\Accounting\Http\Controllers\SettlementStepController;
use Modules\Accounting\Http\Controllers\SetupController;
use Modules\Accounting\Http\Controllers\TaxCodeController;

/*
| Accounting API (loaded by AccountingServiceProvider under /api, group
| "api"): a signed-in organization context, the module on there (403
| otherwise), the organization in the address keeping books (a company or
| personal workspace), and the permission checked in each controller.
*/

Route::middleware(['auth:sanctum', 'org', 'module:accounting'])
    ->prefix('organizations/{organization}/accounting')
    ->group(function () {
        Route::get('setup', [SetupController::class, 'show']);
        Route::get('accounts', [AccountController::class, 'index']);
        Route::get('fiscal-years', [FiscalYearController::class, 'index']);
        Route::get('posting-accounts', [PostingAccountController::class, 'index']);
        Route::get('journals', [JournalController::class, 'index']);
        Route::get('journals/{journal}', [JournalController::class, 'show']);
        Route::get('parties', [PartyController::class, 'index']);
        Route::get('parties/{party}', [PartyController::class, 'show']);
        Route::get('documents', [DocumentController::class, 'index']);
        Route::get('documents/{document}', [DocumentController::class, 'show']);
        Route::get('settlements', [SettlementController::class, 'index']);
        Route::get('settlements/{settlement}', [SettlementController::class, 'show']);
        Route::get('tax-codes', [TaxCodeController::class, 'index']);
        Route::get('opening', [OpeningController::class, 'show']);
        Route::get('bank/accounts', [BankController::class, 'accounts']);
        Route::get('bank/accounts/{account}', [BankController::class, 'show']);
        Route::get('bank/accounts/{account}/reconciliation', [BankController::class, 'preview']);
        // Unknown reports are refused by the controller, after the organization checks.
        Route::get('reports/{report}', ReportController::class)->middleware('throttle:accounting-reports');

        Route::middleware('throttle:tenancy-sensitive')->group(function () {
            Route::post('setup', [SetupController::class, 'store']);
            Route::post('accounts', [AccountController::class, 'store']);
            Route::patch('accounts/{account}', [AccountController::class, 'update']);
            Route::post('fiscal-years', [FiscalYearController::class, 'store']);
            Route::post('periods/{period}/{step}', [FiscalYearController::class, 'period']);
            Route::post('fiscal-years/{year}/{step}', [FiscalYearController::class, 'year']);
            Route::post('reopen-requests/{reopenRequest}/{step}', [FiscalYearController::class, 'reopenRequest']);
            Route::put('opening', [OpeningController::class, 'update']);
            Route::delete('opening', [OpeningController::class, 'destroy']);
            Route::post('opening/{step}', [OpeningController::class, 'step']);
            Route::post('bank/accounts/{account}/import', [BankController::class, 'import'])->middleware('throttle:accounting-bank-import');
            Route::post('bank/accounts/{account}/auto-match', [BankController::class, 'autoMatch']);
            Route::post('bank/accounts/{account}/reconciliations', [BankController::class, 'finish']);
            Route::post('reconciliations/{reconciliation}/reopen', [BankController::class, 'reopen']);
            Route::post('bank-lines/{line}/{step}', [BankController::class, 'line']);
            Route::delete('bank-lines/{line}', [BankController::class, 'destroyLine']);
            Route::put('posting-accounts/{key}', [PostingAccountController::class, 'update']);
            Route::post('journals', [JournalController::class, 'store']);
            Route::patch('journals/{journal}', [JournalController::class, 'update']);
            Route::delete('journals/{journal}', [JournalController::class, 'destroy']);
            Route::post('journals/{journal}/{step}', JournalStepController::class);
            Route::post('parties', [PartyController::class, 'store']);
            Route::patch('parties/{party}', [PartyController::class, 'update']);
            Route::post('documents', [DocumentController::class, 'store']);
            Route::patch('documents/{document}', [DocumentController::class, 'update']);
            Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
            Route::post('documents/{document}/{step}', DocumentStepController::class);
            Route::post('settlements', [SettlementController::class, 'store']);
            Route::post('settlements/{settlement}/{step}', SettlementStepController::class);
            Route::post('tax-codes', [TaxCodeController::class, 'store']);
            Route::patch('tax-codes/{code}', [TaxCodeController::class, 'update']);
        });
    });

/*
| A customer's own invoices in the client's portal (B2B2C, ACC-3c): portal
| members only (the API refuses them everything else), with the portal and
| Accounting on; paying online uses the company's own merchant account.
*/
Route::middleware(['auth:sanctum', 'org', 'module:client_portal', 'module:accounting'])
    ->prefix('portal/accounting')
    ->group(function () {
        Route::get('invoices', [PortalInvoiceController::class, 'index']);
        Route::get('invoices/{document}', [PortalInvoiceController::class, 'show']);
        Route::post('invoices/{document}/pay', [PortalInvoiceController::class, 'pay'])->middleware('throttle:payments-start');
    });
