<?php

use Illuminate\Support\Facades\Route;
use Modules\Letters\Http\Controllers\TemplateController;

// Letterheads, signatures and stamps: signed, thirty minutes (to design or print).
// Module files live under files/ (the app shell's catch-all route leaves it alone).
Route::get('files/letters/{organization}/assets/{asset}', [TemplateController::class, 'assetFile'])
    ->middleware('signed:relative')
    ->name('letters.asset');
