<?php

namespace App\Platform\Tenancy\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Actions\AttemptLogin;
use App\Platform\Tenancy\Actions\IssueContextToken;
use App\Platform\Tenancy\Actions\ListAvailableContexts;
use App\Platform\Tenancy\Http\Requests\EnterContextRequest;
use App\Platform\Tenancy\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token login for API clients (mobile apps, integrations). The browser app
 * uses the cookie session instead: see SessionController.
 */
class AuthController extends Controller
{
    /**
     * Log in. Returns a token that can only pick a context, plus the
     * organizations and partner consoles the user may enter.
     */
    public function login(LoginRequest $request, AttemptLogin $attempt, IssueContextToken $tokens, ListAvailableContexts $contexts): JsonResponse
    {
        $user = $attempt->handle($request->validated('email'), $request->validated('password'), $request->validated('phone'), $request->validated('country_code'));

        return response()->json([
            ...$this->tokenPayload($tokens->forLogin($user)),
            'contexts' => $contexts->handle($user),
        ]);
    }

    /**
     * Enter an organization or a partner console. The id is checked against
     * the user's memberships, stored on a fresh token, and the old token is revoked.
     */
    public function enterContext(EnterContextRequest $request, IssueContextToken $tokens): JsonResponse
    {
        $user = $request->user();

        // Support access lives in a browser session only; it is never put on a token.
        if ($request->filled('support_grant_id')) {
            throw ValidationException::withMessages(['support_grant_id' => __('support.errors.browser_only')]);
        }

        $token = $request->filled('organization_id')
            ? $tokens->forOrganization($user, $request->validated('organization_id'))
            : $tokens->forPartner($user, $request->validated('partner_id'));

        $this->revokeCurrentToken($request);

        return response()->json($this->tokenPayload($token));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->revokeCurrentToken($request);

        return response()->json(['message' => __('tenancy.messages.logged_out')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenPayload(NewAccessToken $token): array
    {
        return [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'context' => match (true) {
                $token->accessToken->organization_id !== null => ['type' => 'organization', 'id' => $token->accessToken->organization_id],
                $token->accessToken->partner_id !== null => ['type' => 'partner', 'id' => $token->accessToken->partner_id],
                default => null,
            },
        ];
    }

    private function revokeCurrentToken(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
