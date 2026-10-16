<?php

use Illuminate\Support\Facades\Route;
use Modules\EducationLectures\Http\Controllers\LectureFileController;

// A lecture's file: only through a short-lived signed link from a lecture.
// Module downloads live under files/ (the app shell's catch-all route leaves it alone).
Route::get('files/lectures/{organization}/{file}', [LectureFileController::class, 'download'])
    ->middleware('signed:relative')
    ->name('education_lectures.files.download');
