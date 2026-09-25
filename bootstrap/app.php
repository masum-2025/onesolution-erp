<?php

use App\Platform\Modules\Http\Middleware\EnsureModuleEnabled;
use App\Platform\Tenancy\Http\Middleware\ResolveOrganization;
use App\Platform\Tenancy\Http\Middleware\ResolvePartner;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'org' => ResolveOrganization::class,
            'partner' => ResolvePartner::class,
            'module' => EnsureModuleEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
