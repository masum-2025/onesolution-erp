<?php

namespace App\Platform\Identity\Http\Middleware;

use App\Platform\Identity\Services\SessionTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser sessions only (API tokens have their own lifetime): a session the
 * person ended elsewhere is signed out here before anything runs; a live
 * one is recorded for the person's list of devices.
 */
class TrackUserSession
{
    public function __construct(private SessionTracker $tracker) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && Auth::guard('web')->check() && $this->tracker->isRevoked($request->session()->getId())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $before = $request->hasSession() ? $request->session()->getId() : null;

        $response = $next($request);

        // After the request: a sign-in in this request is recorded too.
        $user = $request->hasSession() ? Auth::guard('web')->user() : null;
        if ($user !== null) {
            $this->tracker->touch($user, $request, $before);
        }

        return $response;
    }
}
