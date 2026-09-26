<?php

use App\Http\Middleware\ApplyRequestLocale;
use App\Http\Middleware\SecurityHeaders;
use App\Platform\Modules\Http\Middleware\EnsureModuleEnabled;
use App\Platform\Partners\Http\Middleware\ResolveHost;
use App\Platform\Tenancy\Http\Middleware\ResolveOrganization;
use App\Platform\Tenancy\Http\Middleware\ResolvePartner;
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
        // First of all: which account this address belongs to; unknown hosts are refused.
        $middleware->prepend(ResolveHost::class);
        $middleware->append(ApplyRequestLocale::class);
        $middleware->appendToGroup('web', SecurityHeaders::class);
        $middleware->redirectGuestsTo('/login');

        $middleware->alias([
            'org' => ResolveOrganization::class,
            'partner' => ResolvePartner::class,
            'module' => EnsureModuleEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The app's own endpoints always answer in JSON, never with an HTML page.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'session/*') || $request->expectsJson());
    })->create();
