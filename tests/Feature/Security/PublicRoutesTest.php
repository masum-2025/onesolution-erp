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
    // Module files (files/…): the HRM employee documents; class lecture files (signed, ten minutes).
    'GET files/hrm/{organization}/{document}',
    'GET files/lectures/{organization}/{file}',
    // An expense claim's receipt: signed, five minutes.
    'GET files/expenses/{organization}/{receipt}',
    // A job applicant's CV (Recruitment): signed, five minutes.
    'GET files/recruitment/{organization}/{file}',
    // A student's photo (Education): signed, five minutes.
    'GET files/education/{organization}/{student}/photo',
    // Images of document designs and the photo on an issued document (Education): signed, thirty minutes.
    'GET files/education/{organization}/assets/{asset}', 'GET files/education/{organization}/documents/{document}/photo',
    // The QR check of an issued document (Education): throttled per address, one answer for every code that does
    // not verify, no private details.
    'GET api/public/education/verify/{organization}/{code}',
    // Gateway notices: signature checked and verified with the gateway; throttled.
    'POST payments/{gateway}/notify', 'GET payments/{gateway}/return/{outcome}', 'POST payments/{gateway}/return/{outcome}',
    // TLS certificate check for the web server: answers only for verified domains.
    'GET internal/tls/ask',
    // Health for monitoring tools: bearer token (404 without it), counts only, throttled.
    'GET internal/health',
    // Reworded texts for the browser (LANG-1): the current context's wording only (a signed-in
    // person's verified context, else the address's partner), plain texts, throttled.
    'GET api/i18n/{hash}/{locale}/{namespace?}',
    // An office bridge (zkteco-bridge.exe) for an app hosted online: its bearer token only (one
    // company, one bridge, revocable, only the SHA-256 kept), 120 calls a minute per token, 401
    // otherwise; its own company's devices given to it, nothing else.
    'GET api/zkteco/bridge/devices', 'POST api/zkteco/bridge/devices/{device}/readings', 'POST api/zkteco/bridge/devices/{device}/error',
    // A school's public website (WEB-1): published pages only (a draft only with a signed
    // preview link), GET only, no session or cookie, its own strict CSP, 120 a minute per address.
    'GET s/{slug}/{path?}',
    // The sites server asks whether a host may get a certificate (WEB-3a): yes for a site's address
    // or proven own domain, no for any other name; answers nothing else, 600 a minute per address.
    'GET s/_tls',
    // An admission enquiry from a published page (WEB-3b): its own signed token of the site, robots
    // dropped, five an hour per address and a few a day per number, details kept encrypted.
    'POST s/{slug}/enquiry',
    // A company's careers page (REC-1): open public jobs only, no session or cookie, no scripts and a
    // strict CSP, 60 pages a minute per address. Applying: the page's own signed token, robots dropped,
    // ten an hour per address and a few a day per email, CV kind and size checked, details encrypted,
    // nothing seen by the company until the email is confirmed (a POST from the mailed link's page).
    'GET careers/{slug}', 'GET careers/{slug}/jobs/{opening}', 'POST careers/{slug}/jobs/{opening}/apply',
    'GET careers/{slug}/confirm/{token}', 'POST careers/{slug}/confirm/{token}',
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
