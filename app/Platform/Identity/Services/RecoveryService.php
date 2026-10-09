<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Contracts\BotCheck;
use App\Platform\Identity\Exceptions\IdentityException;
use App\Platform\Identity\Models\OtpChallenge;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Notifications\Services\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Forgot password": a code to the account's email or verified phone, then
 * a new password. It cannot be used to take an account over: the code goes
 * only to an address the account already has; afterwards every other
 * session ends, every address of the account is told, and the email and
 * phone stay locked for a while (rule identity.recovery_cooldown_hours).
 * Unknown addresses get the same answer as known ones.
 */
class RecoveryService
{
    public function __construct(
        private SignupGate $gate,
        private BotCheck $bot,
        private OtpService $otp,
        private SessionTracker $sessions,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{channel: string, email?: string|null, phone?: string|null, country_code?: string|null, bot_token?: string|null, locale?: string|null}  $data
     */
    public function start(array $data, ?string $ip): OtpChallenge
    {
        if (! $this->bot->passes($data['bot_token'] ?? null, $ip)) {
            throw IdentityException::botCheckFailed();
        }

        $partner = $this->gate->addressPartner();
        $locale = in_array($data['locale'] ?? null, LanguageRegistry::codes(), true)
            ? $data['locale']
            : (string) config('tenancy.defaults.default_locale');

        if ($data['channel'] === 'sms') {
            if (! $this->gate->smsAvailable($partner)) {
                throw IdentityException::smsUnavailable();
            }
            $destination = PhoneNumber::normalize((string) ($data['phone'] ?? ''), $data['country_code'] ?? null) ?? throw IdentityException::badPhone();
            $user = User::query()->where('phone', $destination)->whereNotNull('phone_verified_at')->first();
        } else {
            $destination = Str::lower(trim((string) ($data['email'] ?? '')));
            $user = User::query()->where('email', $destination)->first();
        }

        return $this->otp->issue(
            purpose: OtpChallenge::RECOVERY,
            channel: $data['channel'],
            destination: $destination,
            partner: $partner,
            user: $user,
            decoy: $user === null,
            ip: $ip,
            locale: $user?->locale ?? $locale,
        );
    }

    public function complete(string $challengeId, string $code, string $password): User
    {
        $challenge = $this->otp->verify($challengeId, OtpChallenge::RECOVERY, $code);
        $user = User::query()->findOrFail($challenge->user_id);

        return DB::transaction(function () use ($challenge, $user, $password) {
            $user->forceFill([
                'password' => $password,
                'password_changed_at' => now(),
                'recovered_at' => now(),
                // The code proved the address.
                ...($challenge->channel === 'mail' && $user->email_verified_at === null ? ['email_verified_at' => now()] : []),
            ])->save();

            $this->sessions->endAll($user);
            $user->tokens()->delete();

            $this->audit->record(action: 'identity.recovered', target: $user, new: ['channel' => $challenge->channel], actor: $user);
            // To every address the account has: if it was not them, they know at once.
            $this->notifier->notify('identity.password_changed', [$user], [
                'time' => now()->timezone('UTC')->format('Y-m-d H:i').' UTC',
            ], $this->gate->addressPartner(), locale: $user->locale, allChannels: true);

            return $user;
        });
    }
}
