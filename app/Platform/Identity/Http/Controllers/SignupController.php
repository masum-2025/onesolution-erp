<?php

namespace App\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Identity\Http\Requests\CodeRequest;
use App\Platform\Identity\Http\Requests\ResendRequest;
use App\Platform\Identity\Http\Requests\SignupRequest;
use App\Platform\Identity\Services\OtpService;
use App\Platform\Identity\Services\SessionSignIn;
use App\Platform\Identity\Services\SignupGate;
use App\Platform\Identity\Services\SignupService;
use App\Platform\Legal\Exceptions\LegalException;
use App\Platform\Legal\Services\LegalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-serve sign-up for the browser app (cookie session): the form, the
 * code, a new code, and the public terms and privacy notice to read first.
 */
class SignupController extends Controller
{
    public function __construct(private SignupService $signup, private OtpService $otp, private SignupGate $gate) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->gate->options()]);
    }

    public function start(SignupRequest $request): JsonResponse
    {
        $challenge = $this->signup->start($request->validated(), $request->ip());
        $data = $this->otp->present($challenge);

        return response()->json(['data' => $data, 'message' => __('identity.messages.code_sent', ['to' => $data['to']])], 202);
    }

    public function verify(CodeRequest $request): JsonResponse
    {
        ['user' => $user] = $this->signup->complete($request->validated('challenge_id'), $request->validated('code'));

        return self::signIn($request, $user, __('identity.messages.signed_up'));
    }

    /**
     * A new code for any open challenge (sign-up, recovery).
     */
    public function resend(ResendRequest $request): JsonResponse
    {
        $data = $this->otp->present($this->otp->resend($request->validated('challenge_id'), $request->ip()));

        return response()->json(['data' => $data, 'message' => __('identity.messages.code_sent', ['to' => $data['to']])]);
    }

    /**
     * The terms or privacy notice in force at this address, for anyone to read before signing up.
     */
    public function legal(string $kind, LegalService $legal): JsonResponse
    {
        if (! in_array($kind, ['terms', 'privacy'], true)) {
            throw LegalException::noDocument();
        }

        $document = $legal->current($this->gate->addressPartner(), $kind) ?? throw LegalException::noDocument();

        return response()->json(['data' => [
            'kind' => $document->kind,
            'version' => $document->version,
            'title' => $document->text('title'),
            'body' => $document->text('body'),
            'published_at' => $document->published_at->toIso8601String(),
        ]]);
    }

    /** Signs the person in, or asks for their second step first (Phase 8-1). */
    public static function signIn(Request $request, User $user, string $message): JsonResponse
    {
        return app(SessionSignIn::class)->start($request, $user, ['message' => $message]);
    }
}
