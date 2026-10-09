<?php

use Illuminate\Support\Facades\Route;
use Modules\Education\Http\Controllers\StudentController;

// A student's photo: only through a short-lived signed link given with the student.
// Module files live under files/ (the app shell's catch-all route leaves it alone).
Route::get('files/education/{organization}/{student}/photo', [StudentController::class, 'photo'])
    ->middleware('signed:relative')
    ->name('education.photo');
