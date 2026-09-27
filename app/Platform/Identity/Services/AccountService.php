<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Exceptions\IdentityException;
use App\Platform\Identity\Models\OtpChallenge;
use App\Platform\Identity\Support\Addresses;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Notifications\Services\Mask;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Packaging\Actions\ApplySectorPackage;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The person's own account: name, language, marketing consent, password,
 * email and phone (each new one proved by a code; the old one is told),
 * and the first-run setup of their personal workspace.
 */
class AccountService
{
    public function __construct(
        private SignupGate $gate,
        private OtpService $otp,
        private SessionTracker $sessions,
        private Notifier $notifier,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private PersonalWorkspaces $workspaces,
        private ApplySectorPackage $package,
    ) {}

    /**
     * @param  array{name?: string, locale?: string, marketing?: bool}  $data
     */
    public function update(User $user, array $data): User
    {
        $old = ['name' => $user->name, 'locale' => $user->locale, 'marketing' => $user->marketing_consent_at !== null];
        $user->forceFill(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'locale' => $data['locale'] ?? null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('marketing', $data)) {
            $user->marketing_consent_at = $data['marketing'] ? ($user->marketing_consent_at ?? now()) : null;
        }
        $user->save();

        $new = ['name' => $user->name, 'locale' => $user->locale, 'marketing' => $user->marketing_consent_at !== null];
        if ($old !== $new) {
            $this->audit->record(action: 'identity.profile_updated', target: $user, old: $old, new: $new, actor: $user);
        }

        return $user;
    }

    public function changePassword(User $user, string $current, string $password, string $keepSessionId): void
    {
        $this->assertPassword($user, $current);

        DB::transaction(function () use ($user, $password, $keepSessionId) {
            $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
            // Everywhere else signs out; this browser stays in.
            $this->sessions->endAll($user, $keepSessionId);
            $user->tokens()->delete();

            $this->audit->record(action: 'identity.password_changed', target: $user, actor: $user);
            $this->notifier->notify('identity.password_changed', [$user], [
                'time' => now()->format('Y-m-d H:i').' UTC',
            ], $this->gate->addressPartner(), locale: $user->locale, allChannels: true);
        });
    }

    /**
     * Sends a code to a new email or phone. Nothing changes until the code is entered.
     *
     * @param  array{channel: string, email?: string|null, phone?: string|null, country_code?: string|null, current_password: string}  $data
     */
    public function startContactChange(User $user, array $data, ?string $ip): OtpChallenge
    {
        $this->assertPassword($user, $data['current_password']);
        $this->assertNoCooldown($user);
        $partner = $this->gate->addressPartner();

        if ($data['channel'] === 'sms') {
            if (! $this->gate->smsAvailable($partner)) {
                throw IdentityException::smsUnavailable();
            }
            $value = PhoneNumber::normalize((string) ($data['phone'] ?? ''), $data['country_code'] ?? null) ?? throw IdentityException::badPhone();
            if (! in_array(PhoneNumber::country($value), $this->gate->phoneCountries($partner), true)) {
                throw IdentityException::phoneCountryNotAllowed();
            }
            $taken = User::query()->where('phone', $value)->whereKeyNot($user->getKey())->exists();
        } else {
            $value = Str::lower(trim((string) ($data['email'] ?? '')));
            if ($partner !== null && (bool) $this->rules->get('identity.block_disposable_email', $this->contexts->forPartner($partner)) && Addresses::isDisposable($value)) {
                throw IdentityException::disposableEmail();
            }
            $taken = User::query()->where('email', $value)->whereKeyNot($user->getKey())->exists();
        }

        // Taken by someone else: the same answer, but the code can never work.
        return $this->otp->issue(OtpChallenge::VERIFY_CONTACT, $data['channel'], $value, $partner, $user, [], $taken, $ip, $user->locale ?? 'en');
    }

    public function completeContactChange(User $user, string $challengeId, string $code): User
    {
        $challenge = OtpChallenge::query()->where('user_id', $user->getKey())->find($challengeId) ?? throw IdentityException::codeExpired();
        $this->assertNoCooldown($user);
        $challenge = $this->otp->verify($challenge->getKey(), OtpChallenge::VERIFY_CONTACT, $code);
        $value = (string) $challenge->details()['to'];
        $isPhone = $challenge->channel === 'sms';

        return DB::transaction(function () use ($user, $value, $isPhone) {
            // The old address hears about it first (a thief changing it would be noticed).
            $old = $isPhone ? $user->phone : $user->email;
            if ($old !== null) {
                $this->notifier->notify('identity.contact_changed', [$user], [
                    'kind' => __($isPhone ? 'identity.kinds.phone' : 'identity.kinds.email', [], $user->locale ?? 'en'),
                    'time' => now()->format('Y-m-d H:i').' UTC',
                ], $this->gate->addressPartner(), locale: $user->locale, allChannels: true);
            }

            $user->forceFill($isPhone
                ? ['phone' => $value, 'phone_verified_at' => now()]
                : ['email' => $value, 'email_verified_at' => now()])->save();

            $this->audit->record(
                action: $isPhone ? 'identity.phone_changed' : 'identity.email_changed',
                target: $user,
                old: [$isPhone ? 'phone' : 'email' => $old === null ? null : ($isPhone ? Mask::phone($old) : Mask::email($old))],
                new: [$isPhone ? 'phone' : 'email' => $isPhone ? Mask::phone($value) : Mask::email($value)],
                actor: $user,
            );

            return $user;
        });
    }

    /**
     * Removes the phone, where an email is left to sign in and recover with.
     */
    public function removePhone(User $user, string $current): User
    {
        $this->assertPassword($user, $current);
        $this->assertNoCooldown($user);
        if ($user->phone === null) {
            return $user;
        }
        if ($user->email === null) {
            throw IdentityException::lastSignInMethod();
        }

        $old = $user->phone;
        $user->forceFill(['phone' => null, 'phone_verified_at' => null])->save();
        $this->audit->record(action: 'identity.phone_removed', target: $user, old: ['phone' => Mask::phone($old)], actor: $user);

        return $user;
    }

    /**
     * First-run setup: language for the person; language, country and (once) the
     * kind of work for their personal workspace.
     *
     * @param  array{locale: string, country_code?: string|null, sector_key?: string|null}  $data
     */
    public function onboard(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['locale' => $data['locale'], 'onboarded_at' => $user->onboarded_at ?? now()])->save();

            $workspace = $this->workspaces->of($user);
            $workspace->fill(array_filter([
                'default_locale' => $data['locale'],
                'country_code' => $data['country_code'] ?? null,
                // The sector is chosen once; later it is changed like any company's.
                'sector_key' => $workspace->sector_key === null ? ($data['sector_key'] ?? null) : null,
            ], fn ($value) => $value !== null))->save();

            if ($workspace->wasChanged('sector_key')) {
                $this->package->handle($workspace->fresh(), $user);
            }

            $this->audit->record(action: 'identity.onboarded', target: $workspace, new: array_filter($data), actor: $user, organizationId: $workspace->getKey(), partnerId: $workspace->partner_id);
        });
    }

    public function cooldownUntil(User $user): ?Carbon
    {
        if ($user->recovered_at === null) {
            return null;
        }

        $partner = $this->gate->addressPartner();
        $hours = (int) $this->rules->get('identity.recovery_cooldown_hours', $partner === null ? $this->contexts->platform() : $this->contexts->forPartner($partner));
        $until = Carbon::parse($user->recovered_at)->addHours($hours);

        return $until->isFuture() ? $until : null;
    }

    private function assertNoCooldown(User $user): void
    {
        $until = $this->cooldownUntil($user);
        if ($until !== null) {
            throw IdentityException::cooldown($until->format('Y-m-d H:i').' UTC');
        }
    }

    public function assertPassword(User $user, string $current): void
    {
        if (! Hash::check($current, $user->getAuthPassword())) {
            $this->audit->record(action: 'identity.password_check_failed', target: $user, actor: $user);

            throw IdentityException::wrongPassword();
        }
    }
}
