<?php

use App\Http\Middleware\ApplyRequestLocale;
use App\Http\Middleware\SecurityHeaders;
use App\Platform\Identity\Http\Middleware\RequireRecentTwoFactor;
use App\Platform\Identity\Http\Middleware\TrackUserSession;
use App\Platform\Modules\Http\Middleware\EnsureModuleEnabled;
use App\Platform\Partners\Http\Middleware\ResolveHost;
use App\Platform\Security\Http\Middleware\BaselineSecurityHeaders;
use App\Platform\Security\Http\Middleware\LogRefusedRequests;
use App\Platform\Security\Http\Middleware\ThrottleByAddress;
use App\Platform\Tenancy\Http\Middleware\ResolveOrganization;
use App\Platform\Tenancy\Http\Middleware\ResolvePartner;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The browser app calls /api with its session cookie (+ CSRF); tokens keep working.
        $middleware->statefulApi();
        // Behind a load balancer, the client's own address comes from X-Forwarded-For, trusted
        // only from these proxies (comma-separated; none by default). Limits and logs need it.
        $middleware->trustProxies(at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))));
        // First of all: which account this address belongs to; unknown hosts are refused.
        $middleware->prepend(ResolveHost::class);
        $middleware->append(ApplyRequestLocale::class);
        // Every response: nosniff, referrer policy, HSTS on HTTPS (Phase 8-2).
        $middleware->append(BaselineSecurityHeaders::class);
        $middleware->appendToGroup('web', SecurityHeaders::class);
        // General limits on /api (Phase 8-2): per address first (before sign-in, so bad
        // tokens are counted too), then per person and per organization.
        $middleware->prependToGroup('api', ThrottleByAddress::class);
        $middleware->throttleApi();
        // Refused (403) and rate-limited (429) answers go to the security log; outermost,
        // so answers from every limit and check are seen.
        $middleware->append(LogRefusedRequests::class);
        // Browser sessions a person ended elsewhere stop at once; live ones are listed on My account.
        $middleware->appendToGroup('web', TrackUserSession::class);
        $middleware->appendToGroup('api', TrackUserSession::class);
        // Before authentication, so an ended session is signed out before it is trusted.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, TrackUserSession::class);
        $middleware->redirectGuestsTo('/login');

        $middleware->alias([
            'org' => ResolveOrganization::class,
            'partner' => ResolvePartner::class,
            'module' => EnsureModuleEnabled::class,
            // Sensitive actions: a recent second step for people who have one (Phase 8-1).
            'two_factor.recent' => RequireRecentTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The app's own endpoints always answer in JSON, never with an HTML page.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'session/*') || $request->expectsJson());
    })->create();
