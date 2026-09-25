<?php

namespace App\Platform\Tenancy\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Actions\AttemptLogin;
use App\Platform\Tenancy\Actions\IssueContextToken;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Http\Requests\EnterContextRequest;
use App\Platform\Tenancy\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Log in. Returns a token that can only pick a context, plus the
     * organizations and partner consoles the user may enter.
     */
    public function login(LoginRequest $request, AttemptLogin $attempt, IssueContextToken $tokens): JsonResponse
    {
        $user = $attempt->handle($request->validated('email'), $request->validated('password'));

        return response()->json([
            ...$this->tokenPayload($tokens->forLogin($user)),
            'contexts' => $this->availableContexts($user),
        ]);
    }

    /**
     * Enter an organization or a partner console. The id is checked against
     * the user's memberships, stored on a fresh token, and the old token is revoked.
     */
    public function enterContext(EnterContextRequest $request, IssueContextToken $tokens): JsonResponse
    {
        $user = $request->user();

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

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function availableContexts(User $user): array
    {
        $organizations = $user->memberships()
            ->with('organization')
            ->where('status', MembershipStatus::Active)
            ->get()
            ->filter(fn ($membership) => $membership->organization->isActive())
            ->map(fn ($membership) => [
                'organization_id' => $membership->organization_id,
                'name' => $membership->organization->displayName(),
                'type' => $membership->organization->type->value,
                'membership_type' => $membership->membership_type->value,
                'is_primary' => $membership->is_primary,
            ])
            ->values()
            ->all();

        $partners = $user->partnerMemberships()
            ->with('partner')
            ->where('status', MembershipStatus::Active)
            ->get()
            ->filter(fn ($partnerUser) => $partnerUser->partner->isActive())
            ->map(fn ($partnerUser) => [
                'partner_id' => $partnerUser->partner_id,
                'name' => $partnerUser->partner->name,
                'role' => $partnerUser->role->value,
            ])
            ->values()
            ->all();

        return ['organizations' => $organizations, 'partners' => $partners];
    }

    private function revokeCurrentToken(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
