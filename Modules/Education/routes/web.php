<?php

use Illuminate\Support\Facades\Route;
use Modules\Education\Http\Controllers\DocumentController;
use Modules\Education\Http\Controllers\DocumentTemplateController;
use Modules\Education\Http\Controllers\StudentController;

// A student's photo: only through a short-lived signed link given with the student.
// Module files live under files/ (the app shell's catch-all route leaves it alone).
Route::get('files/education/{organization}/{student}/photo', [StudentController::class, 'photo'])
    ->middleware('signed:relative')
    ->name('education.photo');

// Images of document designs and the photo copied onto an issued document: signed, thirty minutes (to design or print).
Route::get('files/education/{organization}/assets/{asset}', [DocumentTemplateController::class, 'assetFile'])
    ->middleware('signed:relative')
    ->name('education.document-asset');
Route::get('files/education/{organization}/documents/{document}/photo', [DocumentController::class, 'photo'])
    ->middleware('signed:relative')
    ->name('education.document-photo');
