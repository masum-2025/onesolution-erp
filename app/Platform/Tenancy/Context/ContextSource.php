<?php

namespace App\Platform\Tenancy\Context;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Where the chosen organization / partner console of a request is stored.
 *
 *  - API clients: on the Sanctum token (IssueContextToken).
 *  - The browser app: in the server-side session (EnterSessionContext), bound
 *    to the user and with the same lifetime as a context token.
 *
 * Only ids written here by the server after a membership check are used;
 * ids in the body, query or headers never are. A token always wins over the
 * session, so a token request can never pick up a browser's context.
 */
final class ContextSource
{
    private const SESSION_KEY = 'tenancy_context';

    public function organizationId(Request $request): ?string
    {
        return $this->read($request, 'organization_id', 'organization');
    }

    public function partnerId(Request $request): ?string
    {
        return $this->read($request, 'partner_id', 'partner');
    }

    /**
     * A support session (browser only; tokens never carry support access).
     */
    public function supportGrantId(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return null;
        }

        $stored = $this->session($request);

        return $stored !== null && $stored['type'] === 'support' ? $stored['id'] : null;
    }

    /**
     * When the active context stops being valid (token or session expiry).
     */
    public function expiresAt(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return $token->expires_at?->toIso8601String();
        }

        $stored = $this->session($request);

        return $stored === null ? null : now()->setTimestamp($stored['expires_at'])->toIso8601String();
    }

    public function putInSession(Request $request, string $type, string $id, User $user, CarbonInterface $expiresAt): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'type' => $type,
            'id' => $id,
            'user_id' => $user->getKey(),
            'expires_at' => $expiresAt->getTimestamp(),
        ]);
    }

    public function forgetSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    private function read(Request $request, string $tokenColumn, string $sessionType): ?string
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return $token->getAttribute($tokenColumn);
        }

        $stored = $this->session($request);

        return $stored !== null && $stored['type'] === $sessionType ? $stored['id'] : null;
    }

    /**
     * The session context, only if it belongs to the signed-in user and has
     * not expired. Anything else is removed.
     *
     * @return array{type: string, id: string, expires_at: int}|null
     */
    private function session(Request $request): ?array
    {
        if (! $request->hasSession() || $request->user() === null) {
            return null;
        }

        $stored = $request->session()->get(self::SESSION_KEY);

        if (! is_array($stored)) {
            return null;
        }

        $valid = in_array($stored['type'] ?? null, ['organization', 'partner', 'support'], true)
            && is_string($stored['id'] ?? null)
            && ($stored['user_id'] ?? null) === $request->user()->getAuthIdentifier()
            && is_int($stored['expires_at'] ?? null)
            && $stored['expires_at'] > now()->getTimestamp();

        if (! $valid) {
            $this->forgetSession($request);

            return null;
        }

        return ['type' => $stored['type'], 'id' => $stored['id'], 'expires_at' => $stored['expires_at']];
    }
}
