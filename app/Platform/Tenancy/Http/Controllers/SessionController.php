<?php

namespace App\Platform\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Actions\AttemptLogin;
use App\Platform\Tenancy\Actions\EnterSessionContext;
use App\Platform\Tenancy\Actions\ListAvailableContexts;
use App\Platform\Tenancy\Context\ContextSource;
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
    public function login(LoginRequest $request, AttemptLogin $attempt, ListAvailableContexts $contexts, ContextSource $source): JsonResponse
    {
        $user = $attempt->handle($request->validated('email'), $request->validated('password'));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $source->forgetSession($request);

        return response()->json(['contexts' => $contexts->handle($user)]);
    }

    /**
     * Enter an organization or a partner console for this browser session.
     */
    public function enterContext(EnterContextRequest $request, EnterSessionContext $enter): JsonResponse
    {
        $request->filled('organization_id')
            ? $enter->forOrganization($request, $request->user(), $request->validated('organization_id'))
            : $enter->forPartner($request, $request->user(), $request->validated('partner_id'));

        return response()->json(['message' => __('tenancy.messages.context_entered')]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => __('tenancy.messages.logged_out')]);
    }
}
