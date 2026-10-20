<?php

use Illuminate\Support\Facades\Route;
use Modules\EduWebsite\Http\Controllers\ContentController;
use Modules\EduWebsite\Http\Controllers\PageController;
use Modules\EduWebsite\Http\Controllers\SiteController;

/*
| Website admin API (loaded by EduWebsiteServiceProvider under /api, group
| "api"): a signed-in organization context, the module on there (403
| otherwise), and the permission checked at the company in each controller.
*/

Route::middleware(['auth:sanctum', 'org', 'module:edu_website'])
    ->prefix('organizations/{organization}/website')
    ->group(function () {
        Route::get('/', [SiteController::class, 'show']);
        Route::get('pages', [PageController::class, 'index']);
        Route::get('notices', [ContentController::class, 'notices']);
        Route::get('media', [ContentController::class, 'media']);
        Route::get('preview', [SiteController::class, 'preview']);
        // Whether an address is free while typing it (a few a minute: no list of addresses by guessing).
        Route::get('address-check', [SiteController::class, 'addressCheck'])->middleware('throttle:edu-website-address');

        Route::middleware('throttle:edu-website-writes')->group(function () {
            Route::post('/', [SiteController::class, 'start']);
            Route::patch('/', [SiteController::class, 'update']);
            Route::post('publish', [SiteController::class, 'publish']);
            Route::post('offline', [SiteController::class, 'offline']);
            Route::put('domain', [SiteController::class, 'setDomain']);
            Route::delete('domain', [SiteController::class, 'removeDomain']);
            Route::post('pages', [PageController::class, 'store']);
            Route::patch('pages/{page}', [PageController::class, 'update']);
            Route::delete('pages/{page}', [PageController::class, 'destroy']);
            Route::post('pages/{page}/blocks', [PageController::class, 'addBlock']);
            Route::post('pages/order', [PageController::class, 'order']);
            Route::post('pages/{page}/blocks/order', [PageController::class, 'orderBlocks']);
            Route::patch('pages/{page}/blocks/{block}', [PageController::class, 'updateBlock']);
            Route::delete('pages/{page}/blocks/{block}', [PageController::class, 'removeBlock']);
            Route::post('notices', [ContentController::class, 'storeNotice']);
            Route::patch('notices/{notice}', [ContentController::class, 'updateNotice']);
            Route::delete('notices/{notice}', [ContentController::class, 'destroyNotice']);
            Route::delete('media/{media}', [ContentController::class, 'destroyMedia']);
        });
        // Each check asks the DNS: a few a minute.
        Route::post('domain/verify', [SiteController::class, 'verifyDomain'])->middleware('throttle:edu-website-address');
        // Files are read and drawn again: a few a minute.
        Route::post('media', [ContentController::class, 'upload'])->middleware('throttle:edu-website-uploads');
    });
