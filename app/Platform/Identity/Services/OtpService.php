<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Identity\Exceptions\IdentityException;
use App\Platform\Identity\Jobs\SendOtp;
use App\Platform\Identity\Models\OtpChallenge;
use App\Platform\Identity\Support\Addresses;
use App\Platform\Notifications\Services\Mask;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Partner;

/**
 * One-time codes: issue, resend and check them, within limits per address
 * and per network (rules identity.otp_per_hour_*), a resend wait, a few
 * sends per challenge and a few wrong tries. A decoy challenge (address
 * taken or unknown) behaves the same from outside but can never be passed,
 * so nobody learns whether an address has an account.
 */
class OtpService
{
    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts) {}

    /**
     * @param  array<string, mixed>  $details  Kept encrypted with the challenge.
     */
    public function issue(
        string $purpose,
        string $channel,
        string $destination,
        ?Partner $partner,
        ?User $user = null,
        array $details = [],
        bool $decoy = false,
        ?string $ip = null,
        string $locale = 'en',
    ): OtpChallenge {
        $destinationHash = Addresses::hash($destination);
        $ipHash = $ip === null ? null : Addresses::hash($ip);
        $this->assertWithinLimits($destinationHash, $ipHash, $partner);

        $challenge = new OtpChallenge;
        $challenge->forceFill(['id' => $challenge->newUniqueId()]);
        $code = $this->newCode();

        $challenge->forceFill([
            'purpose' => $purpose,
            'channel' => $channel,
            'destination_hash' => $destinationHash,
            'code_hash' => $decoy ? null : Addresses::codeHash($challenge->getKey(), $code),
            'user_id' => $user?->getKey(),
            'partner_id' => $partner?->getKey(),
            'payload' => OtpChallenge::seal([...$details, 'to' => $destination, 'locale' => $locale]),
            'attempts' => 0,
            'sends' => 1,
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes((int) config('identity.otp.ttl_minutes')),
            'ip_hash' => $ipHash,
        ])->save();

        if (! $decoy) {
            SendOtp::dispatch($channel, $destination, $code, $purpose, $partner?->getKey(), $locale)->afterCommit();
        }

        return $challenge;
    }

    public function resend(string $challengeId, ?string $ip = null): OtpChallenge
    {
        $challenge = OtpChallenge::query()->find($challengeId);
        if ($challenge === null || ! $challenge->isOpen() || $challenge->attempts >= $this->maxAttempts()) {
            throw IdentityException::codeExpired();
        }

        $wait = (int) config('identity.otp.resend_seconds') - (int) $challenge->last_sent_at->diffInSeconds(now(), true);
        if ($wait > 0) {
            throw IdentityException::resendTooSoon($wait);
        }

        if ($challenge->sends >= (int) config('identity.otp.max_sends')) {
            throw IdentityException::codeExpired();
        }

        $partner = $challenge->partner_id === null ? null : Partner::query()->find($challenge->partner_id);
        $this->assertWithinLimits($challenge->destination_hash, $ip === null ? null : Addresses::hash($ip), $partner);

        $details = $challenge->details();
        $code = $this->newCode();
        $challenge->forceFill([
            'code_hash' => $challenge->isDecoy() ? null : Addresses::codeHash($challenge->getKey(), $code),
            'sends' => $challenge->sends + 1,
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes((int) config('identity.otp.ttl_minutes')),
        ])->save();

        if (! $challenge->isDecoy()) {
            SendOtp::dispatch($challenge->channel, (string) $details['to'], $code, $challenge->purpose, $challenge->partner_id, (string) ($details['locale'] ?? 'en'))->afterCommit();
        }

        return $challenge;
    }

    /**
     * Checks a code and uses the challenge up. Wrong codes count; after the
     * last try the challenge is closed and a new one must be started.
     */
    public function verify(string $challengeId, string $purpose, string $code): OtpChallenge
    {
        $challenge = OtpChallenge::query()->where('purpose', $purpose)->find($challengeId);
        if ($challenge === null || ! $challenge->isOpen() || $challenge->attempts >= $this->maxAttempts()) {
            throw IdentityException::codeExpired();
        }

        $valid = ! $challenge->isDecoy() && hash_equals((string) $challenge->code_hash, Addresses::codeHash($challenge->getKey(), $code));

        if (! $valid) {
            $challenge->forceFill(['attempts' => $challenge->attempts + 1])->save();
            $left = $this->maxAttempts() - $challenge->attempts;

            throw $left > 0 ? IdentityException::codeWrong($left) : IdentityException::codeExpired();
        }

        // Use it once, even if two requests race.
        $used = OtpChallenge::query()->whereKey($challenge->getKey())->whereNull('consumed_at')->update(['consumed_at' => now()]);
        if ($used !== 1) {
            throw IdentityException::codeExpired();
        }

        return $challenge->refresh();
    }

    /**
     * What the browser may know about a challenge.
     *
     * @return array<string, mixed>
     */
    public function present(OtpChallenge $challenge): array
    {
        $to = (string) ($challenge->details()['to'] ?? '');

        return [
            'challenge_id' => $challenge->getKey(),
            'channel' => $challenge->channel,
            'to' => $challenge->channel === 'sms' ? Mask::phone($to) : Mask::email($to),
            'length' => (int) config('identity.otp.length'),
            'resend_after' => max(0, (int) config('identity.otp.resend_seconds') - (int) $challenge->last_sent_at->diffInSeconds(now(), true)),
            'expires_at' => $challenge->expires_at->toIso8601String(),
        ];
    }

    private function assertWithinLimits(string $destinationHash, ?string $ipHash, ?Partner $partner): void
    {
        $context = $partner === null ? $this->contexts->platform() : $this->contexts->forPartner($partner);
        $since = now()->subHour();

        $perDestination = (int) $this->rules->get('identity.otp_per_hour_per_destination', $context);
        if ((int) OtpChallenge::query()->where('destination_hash', $destinationHash)->where('last_sent_at', '>=', $since)->sum('sends') >= $perDestination) {
            throw IdentityException::tooManyCodes(60);
        }

        $perIp = (int) $this->rules->get('identity.otp_per_hour_per_ip', $context);
        if ($ipHash !== null && (int) OtpChallenge::query()->where('ip_hash', $ipHash)->where('last_sent_at', '>=', $since)->sum('sends') >= $perIp) {
            throw IdentityException::tooManyCodes(60);
        }
    }

    private function maxAttempts(): int
    {
        return (int) config('identity.otp.max_attempts');
    }

    private function newCode(): string
    {
        $length = (int) config('identity.otp.length');

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}
