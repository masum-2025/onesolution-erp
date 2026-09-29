<?php

namespace App\Platform\Invitations\Http;

use App\Http\Controllers\Controller;
use App\Platform\Identity\Services\SessionSignIn;
use App\Platform\Invitations\Services\InvitationService;
use App\Platform\Notifications\Services\Mask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The page behind an invitation link: who it is for, and setting the
 * password, which also signs the person in. Wrong, used and expired links
 * all get the same answer.
 */
class InvitationController extends Controller
{
    public function __construct(private InvitationService $invitations) {}

    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->find($token);
        if ($invitation === null || ! $invitation->isUsable()) {
            return response()->json(['message' => __('invitations.errors.invalid'), 'code' => 'invalid_invitation'], 404);
        }

        return response()->json(['data' => [
            'name' => $invitation->user->name,
            'email' => Mask::email($invitation->user->email),
            'organization' => $invitation->organization?->displayName(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]]);
    }

    public function accept(Request $request, string $token, SessionSignIn $signIn): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $invitation = $this->invitations->find($token);
        if ($invitation === null || ! $invitation->isUsable()) {
            throw ValidationException::withMessages(['password' => __('invitations.errors.invalid')]);
        }

        $user = $this->invitations->accept($invitation, $validated['password']);

        // An existing person with two-step sign-in still gives their second step (Phase 8-1).
        return $signIn->start($request, $user, ['message' => __('invitations.messages.accepted')]);
    }
}
