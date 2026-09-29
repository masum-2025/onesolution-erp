<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Exceptions\TwoFactorException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The second step of a sign-in (Phase 8-1). After the password (or a reset,
 * an invitation) a person with two-step sign-in is NOT signed in yet: the
 * sign-in waits, for a few minutes and a few tries, for a code from the
 * app, a recovery code or a passkey.
 *
 *  - Browser: the waiting sign-in lives in the session (renewed id).
 *  - API clients: a short-lived random token (only its hash is stored);
 *    they use the app or a recovery code (passkeys are a browser thing).
 *
 * Too many wrong tries end the waiting sign-in: the password is asked again.
 */
class TwoFactorLogin
{
    private const SESSION_KEY = 'two_factor.pending';

    public function __construct(
        private TwoFactorService $twoFactor,
        private PasskeyService $passkeys,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{methods: list<string>, expires_at: string}
     */
    public function begin(Request $request, User $user): array
    {
        $request->session()->regenerate();

        $expires = now()->addMinutes((int) config('identity.two_factor.challenge_minutes'));
        $request->session()->put(self::SESSION_KEY, ['user_id' => $user->getKey(), 'expires_at' => $expires->getTimestamp(), 'attempts' => 0]);

        return ['methods' => $this->methods($request, $user), 'expires_at' => $expires->toIso8601String()];
    }

    public function isPending(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::SESSION_KEY);
    }

    /**
     * Checks an app or recovery code for the waiting sign-in.
     *
     * @return array{0: User, 1: string} The person and the method used.
     */
    public function completeWithCode(Request $request, ?string $code, ?string $recoveryCode): array
    {
        $pending = $this->pending($request);
        $user = User::query()->find($pending['user_id']) ?? throw $this->end($request);

        $method = $this->check($user, $code, $recoveryCode);
        if ($method === null) {
            $this->failed($request, $user, $pending);

            throw TwoFactorException::invalidCode($recoveryCode !== null ? 'recovery_code' : 'code');
        }

        $request->session()->forget(self::SESSION_KEY);

        return [$user, $method];
    }

    /** Passkey options for the waiting sign-in's person. */
    public function passkeyOptions(Request $request): array
    {
        $user = User::query()->find($this->pending($request)['user_id']) ?? throw $this->end($request);

        return $this->passkeys->requestOptions($request, 'login', $user);
    }

    /**
     * @param  array<string, mixed>  $credential
     */
    public function completeWithPasskey(Request $request, array $credential): User
    {
        $pending = $this->pending($request);
        $user = User::query()->find($pending['user_id']) ?? throw $this->end($request);

        try {
            $this->passkeys->verify($request, 'login', $credential, $user);
        } catch (TwoFactorException $exception) {
            $this->failed($request, $user, $pending);

            throw $exception;
        }

        $request->session()->forget(self::SESSION_KEY);

        return $user;
    }

    /**
     * API clients: a token for the second step instead of a session.
     *
     * @return array{token: string, methods: list<string>, expires_at: string}
     */
    public function beginToken(User $user): array
    {
        $token = Str::random(64);
        $minutes = (int) config('identity.two_factor.challenge_minutes');
        Cache::put($this->cacheKey($token), ['user_id' => $user->getKey(), 'attempts' => 0], now()->addMinutes($minutes));

        return [
            'token' => $token,
            'methods' => array_values(array_diff($this->twoFactor->methods($user), ['passkey'])),
            'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
        ];
    }

    /**
     * @return array{0: User, 1: string}
     */
    public function completeToken(string $token, ?string $code, ?string $recoveryCode): array
    {
        $key = $this->cacheKey($token);
        $pending = Cache::get($key);
        $user = is_array($pending) ? User::query()->find($pending['user_id']) : null;
        if ($user === null) {
            throw TwoFactorException::challengeEnded();
        }

        $method = $this->check($user, $code, $recoveryCode);
        if ($method === null) {
            $this->audit->record(action: 'auth.second_step_failed', actor: $user);
            $attempts = $pending['attempts'] + 1;
            if ($attempts >= (int) config('identity.two_factor.max_attempts')) {
                Cache::forget($key);

                throw TwoFactorException::challengeEnded();
            }
            Cache::put($key, [...$pending, 'attempts' => $attempts], now()->addMinutes((int) config('identity.two_factor.challenge_minutes')));

            throw TwoFactorException::invalidCode($recoveryCode !== null ? 'recovery_code' : 'code');
        }

        Cache::forget($key);

        return [$user, $method];
    }

    /** "totp" or "recovery_code" when the code is right, null otherwise. */
    public function check(User $user, ?string $code, ?string $recoveryCode): ?string
    {
        if ($recoveryCode !== null && $recoveryCode !== '') {
            return $this->twoFactor->useRecoveryCode($user, $recoveryCode) ? 'recovery_code' : null;
        }

        return $code !== null && $this->twoFactor->verifyTotp($user, $code) ? 'totp' : null;
    }

    /**
     * @return list<string>
     */
    public function methods(Request $request, User $user): array
    {
        $methods = $this->twoFactor->methods($user);

        return $this->passkeys->available($request) ? $methods : array_values(array_diff($methods, ['passkey']));
    }

    /**
     * @return array{user_id: string, expires_at: int, attempts: int}
     */
    private function pending(Request $request): array
    {
        $pending = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        if (! is_array($pending) || $pending['expires_at'] < now()->getTimestamp()) {
            throw $this->end($request);
        }

        return $pending;
    }

    /**
     * @param  array{user_id: string, expires_at: int, attempts: int}  $pending
     */
    private function failed(Request $request, User $user, array $pending): void
    {
        $this->audit->record(action: 'auth.second_step_failed', actor: $user);

        $attempts = $pending['attempts'] + 1;
        if ($attempts >= (int) config('identity.two_factor.max_attempts')) {
            throw $this->end($request);
        }

        $request->session()->put(self::SESSION_KEY, [...$pending, 'attempts' => $attempts]);
    }

    private function end(Request $request): TwoFactorException
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }

        return TwoFactorException::challengeEnded();
    }

    private function cacheKey(string $token): string
    {
        return 'two_factor:login:'.hash('sha256', $token);
    }
}
