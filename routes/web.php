<?php

use App\Http\Controllers\AppShellController;
use App\Platform\Branding\Http\BrandAssetController;
use App\Platform\DataExport\Http\DataExportController;
use App\Platform\Notifications\Http\Controllers\TemplatePreviewController;
use App\Platform\Partners\Http\Controllers\TlsAskController;
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

// Brand images and the web app manifest of the brand at this address.
Route::get('brand-assets/{partner}/{kind}', [BrandAssetController::class, 'show'])
    ->where(['partner' => '[0-9A-Za-z]{26}', 'kind' => '[a-z_]+']);
Route::get('manifest.webmanifest', [BrandAssetController::class, 'manifest']);

// An email preview for the template editor (only its author, for a few minutes).
Route::get('partner-preview/{preview}', [TemplatePreviewController::class, 'show'])
    ->middleware('auth:web')
    ->where('preview', '[A-Za-z0-9]{40}');

// A data export file: only through a short-lived signed link from the export screen.
Route::get('exports/{export}/download', [DataExportController::class, 'download'])
    ->middleware('signed:relative')
    ->name('exports.download');

// Caddy on-demand TLS asks here before issuing a certificate: verified hosts only.
Route::get('internal/tls/ask', TlsAskController::class);

// Every other page path boots the app; its router decides what to show.
Route::get('/{path?}', AppShellController::class)
    ->where('path', '^(?!api/|session/|sanctum/|up$|build/|storage/).*$')
    ->name('app');
