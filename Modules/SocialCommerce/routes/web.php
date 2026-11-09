<?php

use Illuminate\Support\Facades\Route;
use Modules\SocialCommerce\Http\Controllers\PostController;

// A post's photo: only through a short-lived signed link (module files live under files/, which the app shell leaves alone).
Route::get('files/social/{organization}/media/{media}', [PostController::class, 'file'])
    ->middleware('signed:relative')
    ->name('social_commerce.media');
