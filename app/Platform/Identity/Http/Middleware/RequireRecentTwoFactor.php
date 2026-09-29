<?php

namespace App\Platform\Identity\Http\Middleware;

use App\Platform\Identity\Services\StepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "two_factor.recent" (Phase 8-1): a sensitive action by
 * someone with two-step sign-in needs a recent second step. The app then
 * asks for it (403 step_up_required with the methods) and retries.
 */
class RequireRecentTwoFactor
{
    public function __construct(private StepUp $stepUp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null) {
            $this->stepUp->ensure($request, $user);
        }

        return $next($request);
    }
}
