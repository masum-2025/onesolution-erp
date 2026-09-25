<?php

use App\Http\Controllers\AppShellController;
use App\Platform\Tenancy\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

/*
| Browser app: cookie-session login (CSRF protected by the web group) and the
| single page shell. API clients use /api/auth/* with tokens instead.
*/

Route::prefix('session')->group(function () {
    Route::post('login', [SessionController::class, 'login'])->middleware('throttle:tenancy-login');

    Route::middleware('auth:web')->group(function () {
        Route::post('context', [SessionController::class, 'enterContext'])->middleware('throttle:tenancy-sensitive');
        Route::post('logout', [SessionController::class, 'logout']);
    });
});

// Every other page path boots the app; its router decides what to show.
Route::get('/{path?}', AppShellController::class)
    ->where('path', '^(?!api/|session/|sanctum/|up$|build/|storage/).*$')
    ->name('app');
