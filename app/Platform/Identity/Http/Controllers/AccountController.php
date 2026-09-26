<?php

namespace App\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Exceptions\IdentityException;
use App\Platform\Identity\Http\Requests\AccountRequest;
use App\Platform\Identity\Http\Requests\CodeRequest;
use App\Platform\Identity\Http\Requests\ContactRequest;
use App\Platform\Identity\Http\Requests\CurrentPasswordRequest;
use App\Platform\Identity\Http\Requests\OnboardingRequest;
use App\Platform\Identity\Http\Requests\PasswordRequest;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Identity\Services\AccountService;
use App\Platform\Identity\Services\OtpService;
use App\Platform\Identity\Services\SessionTracker;
use App\Platform\Identity\Services\SignupGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "My account": the signed-in person's own identity (not an organization's
 * data, so no organization context is needed): name, language, consent,
 * password, email and phone, signed-in devices and the first-run setup.
 */
class AccountController extends Controller
{
    public function __construct(
        private AccountService $account,
        private OtpService $otp,
        private SessionTracker $sessions,
        private SignupGate $gate,
        private AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    public function update(AccountRequest $request): JsonResponse
    {
        $user = $this->account->update($request->user(), $request->validated());

        return response()->json(['data' => $this->present($user), 'message' => __('identity.messages.profile_saved')]);
    }

    public function password(PasswordRequest $request): JsonResponse
    {
        $this->account->changePassword($request->user(), $request->validated('current_password'), $request->validated('password'), $this->sessionId($request));

        return response()->json(['message' => __('identity.messages.password_changed')]);
    }

    public function contact(ContactRequest $request): JsonResponse
    {
        $data = $this->otp->present($this->account->startContactChange($request->user(), $request->validated(), $request->ip()));

        return response()->json(['data' => $data, 'message' => __('identity.messages.code_sent', ['to' => $data['to']])], 202);
    }

    public function verifyContact(CodeRequest $request): JsonResponse
    {
        $before = $request->user()->phone;
        $user = $this->account->completeContactChange($request->user(), $request->validated('challenge_id'), $request->validated('code'));
        $kind = $user->phone !== $before ? 'phone' : 'email';

        return response()->json(['data' => $this->present($user), 'message' => __('identity.messages.contact_changed', ['kind' => __("identity.kinds.{$kind}")])]);
    }

    public function removePhone(CurrentPasswordRequest $request): JsonResponse
    {
        $user = $this->account->removePhone($request->user(), $request->validated('current_password'));

        return response()->json(['data' => $this->present($user), 'message' => __('identity.messages.phone_removed')]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $current = UserSession::hashOf($this->sessionId($request));

        return response()->json(['data' => $this->sessions->active($request->user())->map(fn (UserSession $session) => [
            'id' => $session->getKey(),
            'browser' => $session->browser,
            'platform' => $session->platform,
            'ip' => $session->ip,
            'last_seen_at' => $session->last_seen_at->toIso8601String(),
            'current' => $session->session_hash === $current,
        ])->values()]);
    }

    public function endSession(Request $request, string $session): JsonResponse
    {
        if (! $this->sessions->end($request->user(), $session)) {
            throw IdentityException::sessionNotFound();
        }

        return response()->json(['message' => __('identity.messages.session_ended')]);
    }

    public function endOtherSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $this->sessions->endAll($user, $this->sessionId($request) ?: null);
        $user->tokens()->delete();

        $this->audit->record(action: 'identity.sessions_ended', target: $user, new: ['count' => $count], actor: $user);

        return response()->json(['message' => __('identity.messages.sessions_ended')]);
    }

    public function onboarding(OnboardingRequest $request): JsonResponse
    {
        $this->account->onboard($request->user(), $request->validated());

        return response()->json(['data' => $this->present($request->user()->fresh()), 'message' => __('identity.messages.onboarded')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        $cooldown = $this->account->cooldownUntil($user);

        return [
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'phone' => $user->phone,
            'phone_verified' => $user->phone_verified_at !== null,
            'locale' => $user->locale,
            'marketing' => $user->marketing_consent_at !== null,
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'locked_until' => $cooldown?->toIso8601String(),
            'onboarded' => $user->onboarded_at !== null,
            'options' => [
                'phone' => $this->gate->smsAvailable($this->gate->addressPartner()),
                'phone_countries' => $this->gate->options()['phone_countries'],
            ],
        ];
    }

    private function sessionId(Request $request): string
    {
        return $request->hasSession() ? $request->session()->getId() : '';
    }
}
