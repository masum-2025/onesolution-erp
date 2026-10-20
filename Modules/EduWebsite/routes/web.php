<?php

use App\Platform\Sites\SiteResolver;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\EduWebsite\Http\Controllers\PublicSiteController;

/*
| A school's public website on the app's own host: "/s/{slug}/…" (for
| anyone; no session or cookie is started; limited per visitor in the
| controller). The same site at "{slug}.{sites domain}" is served by
| ServeSiteByHost.
*/

// The sites server asks before making a certificate for a host (on-demand TLS); "_" is never in a slug.
Route::get('s/_tls', [PublicSiteController::class, 'tls'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, AddQueuedCookiesToResponse::class])
    ->name('edu-website.tls');

Route::get('s/{slug}/{path?}', [PublicSiteController::class, 'byPath'])
    ->where('slug', SiteResolver::SLUG_PATTERN)
    ->where('path', '.*')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, AddQueuedCookiesToResponse::class])
    ->name('edu-website.public');
