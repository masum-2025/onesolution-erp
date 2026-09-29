<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Exceptions\TwoFactorException;
use App\Platform\Rules\RuleResolver;
use Illuminate\Http\Request;

/**
 * Confirming it is really you before a sensitive action (Phase 8-1):
 * approving rules or held money, payment accounts, roles, API keys,
 * transfers, security settings. Only for people with a second step; the
 * last one must be newer than identity.step_up_minutes.
 *
 *  - Browser: the time of the last second step is kept in the session
 *    (set at sign-in and by confirming again).
 *  - API clients: an app code in the X-Two-Factor-Code header.
 */
class StepUp
{
    private const SESSION_KEY = 'two_factor.confirmed_at';

    public const HEADER = 'X-Two-Factor-Code';

    public function __construct(
        private RuleResolver $rules,
        private TwoFactorLogin $login,
        private PasskeyService $passkeys,
        private AuditLogger $audit,
    ) {}

    public function markConfirmed(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
        }
    }

    public function satisfied(Request $request, User $user): bool
    {
        if (! $user->hasTwoFactor()) {
            return true;
        }

        $at = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        if (is_int($at) && $at >= now()->subMinutes((int) $this->rules->get('identity.step_up_minutes'))->getTimestamp()) {
            return true;
        }

        $code = $request->header(self::HEADER);

        return is_string($code) && $code !== '' && $this->login->check($user, $code, null) !== null;
    }

    /**
     * Confirms with an app code, a recovery code or a passkey answer.
     *
     * @param  array<string, mixed>|null  $credential
     */
    public function confirm(Request $request, User $user, ?string $code, ?string $recoveryCode, ?array $credential): string
    {
        if ($credential !== null) {
            $this->passkeys->verify($request, 'confirm', $credential, $user);
            $method = 'passkey';
        } else {
            $method = $this->login->check($user, $code, $recoveryCode);
            if ($method === null) {
                $this->audit->record(action: 'auth.second_step_failed', actor: $user);

                throw TwoFactorException::invalidCode($recoveryCode !== null ? 'recovery_code' : 'code');
            }
        }

        $this->markConfirmed($request);

        return $method;
    }

    public function ensure(Request $request, User $user): void
    {
        if (! $this->satisfied($request, $user)) {
            throw TwoFactorException::stepUpRequired($this->login->methods($request, $user));
        }
    }
}
