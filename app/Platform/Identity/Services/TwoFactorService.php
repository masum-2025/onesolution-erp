<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Branding\BrandResolver;
use App\Platform\Identity\Exceptions\TwoFactorException;
use App\Platform\Identity\Models\Passkey;
use App\Platform\Identity\Models\RecoveryCode;
use App\Platform\Identity\Models\TotpSecret;
use App\Platform\Partners\HostContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-step sign-in for one person (Phase 8-1): the authenticator app
 * (TOTP, RFC 6238), single-use recovery codes, and whether the person has a
 * second step at all (users.mfa_enabled_at, kept in step with the app and
 * their passkeys).
 *
 *  - A code is valid for its 30-second step and one step either side, and
 *    never twice (the last step used is kept).
 *  - Recovery codes: ten, shown once, stored as SHA-256 only. New ones are
 *    made when the first second step is added, or on request.
 *  - The app's name in the authenticator is the brand at this address.
 */
class TwoFactorService
{
    private const RECOVERY_CODES = 10;

    private const RECOVERY_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const WINDOW = 1;

    public function __construct(
        private AuditLogger $audit,
        private BrandResolver $brands,
        private HostContext $host,
    ) {}

    /**
     * Starts (or restarts) setting up the app: a new secret, not active until confirmed.
     *
     * @return array{secret: string, uri: string, qr: string}
     */
    public function startTotp(User $user): array
    {
        $existing = TotpSecret::query()->where('user_id', $user->getKey())->first();
        if ($existing?->isConfirmed()) {
            throw TwoFactorException::alreadyEnabled();
        }

        $google = new Google2FA;
        $secret = $google->generateSecretKey(32);

        $row = $existing ?? new TotpSecret;
        $row->forceFill(['user_id' => $user->getKey(), 'secret' => $secret, 'confirmed_at' => null, 'last_used_step' => null])->save();

        $issuer = $this->brands->for($this->host->partner(), $this->host->client())['name'];
        $uri = $google->getQRCodeUrl($issuer, $user->email ?? $user->phone ?? $user->name, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($uri);

        return ['secret' => $secret, 'uri' => $uri, 'qr' => 'data:image/svg+xml;base64,'.base64_encode($svg)];
    }

    /**
     * The first code from the app turns it on.
     *
     * @return list<string>|null New recovery codes when this is the person's first second step.
     */
    public function confirmTotp(User $user, string $code): ?array
    {
        $row = TotpSecret::query()->where('user_id', $user->getKey())->first();
        if ($row === null) {
            throw TwoFactorException::notStarted();
        }
        if ($row->isConfirmed()) {
            throw TwoFactorException::alreadyEnabled();
        }

        $step = $this->matchStep($row, $code) ?? throw TwoFactorException::invalidCode();

        return DB::transaction(function () use ($user, $row, $step) {
            $row->forceFill(['confirmed_at' => now(), 'last_used_step' => $step])->save();
            $codes = $this->codesForFirstStep($user);
            $this->refresh($user);

            $this->audit->record(action: 'identity.totp_enabled', target: $user, actor: $user);

            return $codes;
        });
    }

    /** A code from the confirmed app; each code works once. */
    public function verifyTotp(User $user, string $code): bool
    {
        $row = TotpSecret::query()->where('user_id', $user->getKey())->whereNotNull('confirmed_at')->first();
        if ($row === null) {
            return false;
        }

        $step = $this->matchStep($row, $code);
        if ($step === null) {
            return false;
        }

        // Only the first request to claim this step wins (two tabs, a replayed code).
        return TotpSecret::query()
            ->whereKey($row->getKey())
            ->where(fn ($query) => $query->whereNull('last_used_step')->orWhere('last_used_step', '<', $step))
            ->update(['last_used_step' => $step]) === 1;
    }

    public function disableTotp(User $user): void
    {
        TotpSecret::query()->where('user_id', $user->getKey())->delete();
        $this->refresh($user);

        $this->audit->record(action: 'identity.totp_disabled', target: $user, actor: $user);
    }

    public function hasTotp(User $user): bool
    {
        return TotpSecret::query()->where('user_id', $user->getKey())->whereNotNull('confirmed_at')->exists();
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = DB::transaction(fn () => $this->makeRecoveryCodes($user));
        $this->audit->record(action: 'identity.recovery_codes_regenerated', target: $user, actor: $user);

        return $codes;
    }

    public function useRecoveryCode(User $user, string $code): bool
    {
        $hash = hash('sha256', self::normalizeRecoveryCode($code));

        $used = RecoveryCode::query()
            ->where('user_id', $user->getKey())
            ->where('code_hash', $hash)
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;

        if ($used) {
            $this->audit->record(action: 'identity.recovery_code_used', target: $user, actor: $user, new: ['left' => $this->recoveryCodesLeft($user)]);
        }

        return $used;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return RecoveryCode::query()->where('user_id', $user->getKey())->whereNull('used_at')->count();
    }

    /**
     * New recovery codes when the person has none yet (their first second step).
     *
     * @return list<string>|null
     */
    public function codesForFirstStep(User $user): ?array
    {
        return $this->recoveryCodesLeft($user) > 0 ? null : $this->makeRecoveryCodes($user);
    }

    /** Keeps users.mfa_enabled_at in step with the app and passkeys. */
    public function refresh(User $user): void
    {
        $has = $this->hasTotp($user) || Passkey::query()->where('user_id', $user->getKey())->exists();

        if ($has && $user->mfa_enabled_at === null) {
            $user->forceFill(['mfa_enabled_at' => now()])->save();
        } elseif (! $has && $user->mfa_enabled_at !== null) {
            $user->forceFill(['mfa_enabled_at' => null])->save();
        }
    }

    /** Removes every second step (an approved admin reset). */
    public function clearAll(User $user): void
    {
        DB::transaction(function () use ($user) {
            TotpSecret::query()->where('user_id', $user->getKey())->delete();
            RecoveryCode::query()->where('user_id', $user->getKey())->delete();
            Passkey::query()->where('user_id', $user->getKey())->delete();
            $this->refresh($user);
        });
    }

    /** Which second steps the person can use to confirm (for the sign-in and step-up screens). */
    public function methods(User $user): array
    {
        $methods = [];
        if ($this->hasTotp($user)) {
            $methods[] = 'totp';
        }
        if (Passkey::query()->where('user_id', $user->getKey())->exists()) {
            $methods[] = 'passkey';
        }
        if ($methods !== [] && $this->recoveryCodesLeft($user) > 0) {
            $methods[] = 'recovery_code';
        }

        return $methods;
    }

    /** The 30-second time step codes belong to (RFC 6238). */
    public static function currentStep(): int
    {
        return intdiv(now()->getTimestamp(), 30);
    }

    public static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** The time step the code belongs to, or null. */
    private function matchStep(TotpSecret $row, string $code): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $google = new Google2FA;
        $google->setWindow(self::WINDOW);
        // With an old step (0 = none yet) the library returns the matching step, not just true.
        // The step comes from the app clock (now()), not PHP's own time().
        $step = $google->verifyKeyNewer($row->secret, $code, $row->last_used_step ?? 0, null, self::currentStep());

        return is_int($step) ? $step : null;
    }

    /**
     * @return list<string>
     */
    private function makeRecoveryCodes(User $user): array
    {
        RecoveryCode::query()->where('user_id', $user->getKey())->delete();

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);

            (new RecoveryCode)->forceFill(['user_id' => $user->getKey(), 'code_hash' => hash('sha256', $raw)])->save();
        }

        return $codes;
    }
}
