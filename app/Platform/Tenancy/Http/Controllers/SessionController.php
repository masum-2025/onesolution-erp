<?php

namespace App\Platform\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Identity\Services\SessionSignIn;
use App\Platform\Identity\Services\SessionTracker;
use App\Platform\Tenancy\Actions\AttemptLogin;
use App\Platform\Tenancy\Actions\EnterSessionContext;
use App\Platform\Tenancy\Http\Requests\EnterContextRequest;
use App\Platform\Tenancy\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Cookie-session login for the first-party browser app. No token ever
 * reaches the browser: the session cookie is HttpOnly and requests carry
 * the CSRF token. API clients use AuthController (tokens) instead.
 */
class SessionController extends Controller
{
    /**
     * The password. With two-step sign-in the answer is { two_factor: { methods } }
     * and the person is not signed in until TwoFactorSessionController finishes it.
     */
    public function login(LoginRequest $request, AttemptLogin $attempt, SessionSignIn $signIn): JsonResponse
    {
        $user = $attempt->handle($request->validated('email'), $request->validated('password'), $request->validated('phone'), $request->validated('country_code'));

        return $signIn->start($request, $user);
    }

    /**
     * Enter an organization or a partner console for this browser session.
     */
    public function enterContext(EnterContextRequest $request, EnterSessionContext $enter): JsonResponse
    {
        match (true) {
            $request->filled('organization_id') => $enter->forOrganization($request, $request->user(), $request->validated('organization_id')),
            $request->filled('support_grant_id') => $enter->forSupport($request, $request->user(), $request->validated('support_grant_id')),
            default => $enter->forPartner($request, $request->user(), $request->validated('partner_id')),
        };

        return response()->json(['message' => __('tenancy.messages.context_entered')]);
    }

    public function logout(Request $request, SessionTracker $sessions): JsonResponse
    {
        $sessions->endCurrent($request);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => __('tenancy.messages.logged_out')]);
    }
}
