<?php

namespace App\Platform\PartnerApi\Http\Middleware;

use App\Platform\PartnerApi\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The key must have been given this scope when it was created.
 *
 *   ->middleware('api.scope:clients:write')
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        if (! $request->attributes->get('partner_api_key')?->allows($scope)) {
            throw ApiException::missingScope($scope);
        }

        return $next($request);
    }
}
