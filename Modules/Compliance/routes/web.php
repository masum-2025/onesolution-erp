<?php

use Illuminate\Support\Facades\Route;
use Modules\Compliance\Http\Controllers\RenewalController;

// A file a provider sent with a result (certificate, report): only through a short-lived signed link.
// Module downloads live under files/ (the app shell's catch-all route leaves it alone).
Route::get('files/compliance/{organization}/results/{result}/{index}', [RenewalController::class, 'file'])
    ->middleware('signed:relative')
    ->name('compliance.results.file');
