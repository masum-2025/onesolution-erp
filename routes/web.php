<?php

use App\Http\Controllers\AppShellController;
use App\Platform\Audit\Http\AdvancedAuditController;
use App\Platform\Branding\Http\BrandAssetController;
use App\Platform\Branding\Http\ClientBrandAssetController;
use App\Platform\DataExport\Http\DataExportController;
use App\Platform\Identity\Http\Controllers\RecoveryController;
use App\Platform\Identity\Http\Controllers\SignupController;
use App\Platform\Identity\Http\Controllers\TwoFactorSessionController;
use App\Platform\Identity\Http\Middleware\TrackUserSession;
use App\Platform\Invitations\Http\InvitationController;
use App\Platform\Notifications\Http\Controllers\TemplatePreviewController;
use App\Platform\Partners\Http\Controllers\TlsAskController;
use App\Platform\Payments\Http\GatewayCallbackController;
use App\Platform\Portal\Http\Controllers\PortalJoinController;
use App\Platform\Tenancy\Http\Controllers\SessionController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
| Browser app: cookie-session login (CSRF protected by the web group) and the
| single page shell. API clients use /api/auth/* with tokens instead.
*/

Route::prefix('session')->group(function () {
    Route::post('login', [SessionController::class, 'login'])->middleware('throttle:tenancy-login');

    // Two-step sign-in (Phase 8-1): the second step, and passkeys with or without a password.
    Route::middleware('throttle:two-factor')->group(function () {
        Route::post('two-factor', [TwoFactorSessionController::class, 'code']);
        Route::post('passkey/options', [TwoFactorSessionController::class, 'passkeyOptions']);
        Route::post('passkey', [TwoFactorSessionController::class, 'passkey']);
    });
    // Invitation links: see who it is for, set the password (signs in).
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:tenancy-login');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])->middleware('throttle:tenancy-login');

    // Self-serve sign-up and "forgot password" (Phase 5C-1): bot check, codes, limits.
    Route::get('signup/options', [SignupController::class, 'options']);
    Route::get('legal/{kind}', [SignupController::class, 'legal'])->where('kind', 'terms|privacy');
    Route::post('signup', [SignupController::class, 'start'])->middleware('throttle:identity-start');
    Route::post('recovery', [RecoveryController::class, 'start'])->middleware('throttle:identity-start');
    Route::middleware('throttle:identity-code')->group(function () {
        Route::post('signup/verify', [SignupController::class, 'verify']);
        Route::post('recovery/verify', [RecoveryController::class, 'complete']);
        Route::post('otp/resend', [SignupController::class, 'resend']);
    });

    // Joining a client's portal with an invitation (Phase 5C-4).
    Route::middleware('throttle:portal-join')->group(function () {
        Route::get('portal/invitations/{key}', [PortalJoinController::class, 'show'])->where('key', '[A-Za-z0-9-]{10,64}');
        Route::post('portal/signup', [PortalJoinController::class, 'signup']);
        Route::post('portal/signup/verify', [PortalJoinController::class, 'verify']);
        Route::post('portal/join', [PortalJoinController::class, 'join'])->middleware('auth:web');
    });

    Route::middleware('auth:web')->group(function () {
        Route::post('context', [SessionController::class, 'enterContext'])->middleware('throttle:tenancy-sensitive');
        Route::post('logout', [SessionController::class, 'logout']);
    });
});

// Brand images and the web app manifest of the brand at this address.
Route::get('brand-assets/{partner}/{kind}', [BrandAssetController::class, 'show'])
    ->where(['partner' => '[0-9A-Za-z]{26}', 'kind' => '[a-z_]+']);
Route::get('manifest.webmanifest', [BrandAssetController::class, 'manifest']);
Route::get('client-brand-assets/{organization}/logo', [ClientBrandAssetController::class, 'show'])->where('organization', '[0-9A-Za-z]{26}');

// An email preview for the template editor (only its author, for a few minutes).
Route::get('partner-preview/{preview}', [TemplatePreviewController::class, 'show'])
    ->middleware('auth:web')
    ->where('preview', '[A-Za-z0-9]{40}');

// A data export file: only through a short-lived signed link from the export screen.
Route::get('exports/{export}/download', [DataExportController::class, 'download'])
    ->middleware('signed:relative')
    ->name('exports.download');

// Audit log exports (advanced_audit, Phase 9-1): signed, short-lived links.
Route::get('audit-exports/{export}/download', [AdvancedAuditController::class, 'download'])
    ->middleware('signed:relative')
    ->name('audit-exports.download');

// Payment gateways (Phase 5C-2): their server's notice, and the browser coming back.
// No session and no CSRF: the gateway posts from its own site, and a new session
// here would replace the person's. Every message is checked with the gateway itself.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, AddQueuedCookiesToResponse::class, TrackUserSession::class])
    ->middleware('throttle:payments-callback')
    ->where(['gateway' => '[a-z0-9_]+'])
    ->group(function () {
        Route::post('payments/{gateway}/notify', [GatewayCallbackController::class, 'notify']);
        Route::match(['get', 'post'], 'payments/{gateway}/return/{outcome}', [GatewayCallbackController::class, 'return'])
            ->where('outcome', 'success|fail|cancel');
    });

// Caddy on-demand TLS asks here before issuing a certificate: verified hosts only.
Route::get('internal/tls/ask', TlsAskController::class);

// Every other page path boots the app; its router decides what to show.
Route::get('/{path?}', AppShellController::class)
    ->where('path', '^(?!api/|session/|sanctum/|up$|build/|storage/).*$')
    ->name('app');
