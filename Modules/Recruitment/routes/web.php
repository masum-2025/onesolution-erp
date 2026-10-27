<?php

use App\Platform\Sites\SiteResolver;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Recruitment\Http\Controllers\FileController;
use Modules\Recruitment\Http\Controllers\PublicCareersController;

/*
| A company's public careers page "/careers/{slug}" (for anyone; no session
| or cookie is started; limited per visitor in the controller; forms carry
| their own signed token), and CVs through short signed links.
*/

$public = [StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, AddQueuedCookiesToResponse::class];

Route::get('careers/{slug}', [PublicCareersController::class, 'index'])
    ->where('slug', SiteResolver::SLUG_PATTERN)->withoutMiddleware($public)->name('recruitment.careers');
Route::get('careers/{slug}/jobs/{opening}', [PublicCareersController::class, 'show'])
    ->where('slug', SiteResolver::SLUG_PATTERN)->withoutMiddleware($public)->name('recruitment.careers.job');
Route::post('careers/{slug}/jobs/{opening}/apply', [PublicCareersController::class, 'apply'])
    ->where('slug', SiteResolver::SLUG_PATTERN)->middleware('throttle:recruitment-apply')->withoutMiddleware($public)->name('recruitment.careers.apply');
Route::get('careers/{slug}/confirm/{token}', [PublicCareersController::class, 'confirmPage'])
    ->where('slug', SiteResolver::SLUG_PATTERN)->withoutMiddleware($public)->name('recruitment.careers.confirm-page');
Route::post('careers/{slug}/confirm/{token}', [PublicCareersController::class, 'confirm'])
    ->where('slug', SiteResolver::SLUG_PATTERN)->middleware('throttle:recruitment-apply')->withoutMiddleware($public)->name('recruitment.careers.confirm');

// A CV: only through a short-lived signed link from the application (module files live under files/).
Route::get('files/recruitment/{organization}/{file}', [FileController::class, 'show'])
    ->middleware('signed:relative')
    ->name('recruitment.files.show');
