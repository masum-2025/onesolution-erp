<?php

use Illuminate\Support\Facades\Route;
use Modules\Hrm\Http\Controllers\EmployeeDocumentController;

// An employee document: only through a short-lived signed link from the documents list.
// Module downloads live under files/ (the app shell's catch-all route leaves it alone).
Route::get('files/hrm/{organization}/{document}', [EmployeeDocumentController::class, 'download'])
    ->middleware('signed:relative')
    ->name('hrm.documents.download');
