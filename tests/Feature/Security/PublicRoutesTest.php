<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * Phase 8-2: every endpoint needs a signed-in person or an API key, except
 * the reviewed list below. A new public endpoint fails this test until it is
 * added here on purpose, with its protection noted.
 */

const REVIEWED_PUBLIC_ROUTES = [
    // Sign-in and the second step: throttled per address and account.
    'POST api/auth/login', 'POST api/auth/two-factor',
    'POST session/login', 'POST session/two-factor', 'POST session/passkey/options', 'POST session/passkey',
    // Invitations, sign-up and recovery: throttled, bot check, one-time codes.
    'GET session/invitations/{token}', 'POST session/invitations/{token}',
    'GET session/signup/options', 'GET session/legal/{kind}',
    'POST session/signup', 'POST session/recovery', 'POST session/signup/verify', 'POST session/recovery/verify', 'POST session/otp/resend',
    'GET session/portal/invitations/{key}', 'POST session/portal/signup', 'POST session/portal/signup/verify',
    // Public brand images and the app manifest for the address's brand.
    'GET brand-assets/{partner}/{kind}', 'GET client-brand-assets/{organization}/logo', 'GET manifest.webmanifest',
    // Signed, expiring download links. (Laravel's generic storage/{path} route is off, Phase 11.)
    'GET exports/{export}/download', 'GET audit-exports/{export}/download',
    // Module files (files/…): the HRM employee documents.
    'GET files/hrm/{organization}/{document}',
    // A student's photo (Education): signed, five minutes.
    'GET files/education/{organization}/{student}/photo',
    // Gateway notices: signature checked and verified with the gateway; throttled.
    'POST payments/{gateway}/notify', 'GET payments/{gateway}/return/{outcome}', 'POST payments/{gateway}/return/{outcome}',
    // TLS certificate check for the web server: answers only for verified domains.
    'GET internal/tls/ask',
    // Health for monitoring tools: bearer token (404 without it), counts only, throttled.
    'GET internal/health',
    // Reworded texts for the browser (LANG-1): the current context's wording only (a signed-in
    // person's verified context, else the address's partner), plain texts, throttled.
    'GET api/i18n/{hash}/{locale}/{namespace?}',
    // Health check, CSRF cookie, and the single page app shell.
    'GET up', 'GET sanctum/csrf-cookie', 'GET {path?}',
];

it('requires sign-in or an API key everywhere except the reviewed public endpoints', function () {
    $public = [];

    foreach (Route::getRoutes() as $route) {
        /** @var RouteDefinition $route */
        $guarded = collect($route->gatherMiddleware())->contains(
            fn ($middleware) => is_string($middleware) && (str_starts_with($middleware, 'auth') || $middleware === 'partner.key'),
        );

        if (! $guarded) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $public[] = "{$method} {$route->uri()}";
            }
        }
    }

    expect(array_values(array_diff($public, REVIEWED_PUBLIC_ROUTES)))->toBe([])
        // A reviewed entry that no longer exists is removed from the list too.
        ->and(array_values(array_diff(REVIEWED_PUBLIC_ROUTES, $public)))->toBe([]);
});

it('limits every public endpoint that takes input', function () {
    $unlimited = [];

    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $isPublicWrite = array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== []
            && in_array(collect($route->methods())->first().' '.$route->uri(), REVIEWED_PUBLIC_ROUTES, true);
        $limited = in_array('api', $middleware, true)
            || collect($middleware)->contains(fn ($name) => is_string($name) && str_starts_with($name, 'throttle'));

        if ($isPublicWrite && ! $limited) {
            $unlimited[] = $route->uri();
        }
    }

    expect($unlimited)->toBe([]);
});
