<?php

use Illuminate\Support\Facades\Route;
use Modules\Expenses\Http\Controllers\ReceiptController;

// A receipt: only through a short-lived signed link from the claim (module files live under files/).
Route::get('files/expenses/{organization}/{receipt}', [ReceiptController::class, 'show'])
    ->middleware('signed:relative')
    ->name('expenses.receipts.show');
