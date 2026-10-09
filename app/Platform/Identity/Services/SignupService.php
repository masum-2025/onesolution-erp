<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Contracts\BotCheck;
use App\Platform\Identity\Events\UserSignedUp;
use App\Platform\Identity\Exceptions\IdentityException;
use App\Platform\Identity\Models\OtpChallenge;
use App\Platform\Identity\Support\Addresses;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Legal\Services\LegalService;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Self-serve sign-up in two steps: the form sends a code to the email or
 * phone (nothing is created yet), and the right code creates the account,
 * the personal workspace and the terms acceptance, all at once. If the
 * address already has an account, the answer looks the same, no code is
 * sent, and the account's owner is told someone tried.
 */
class SignupService
{
    public function __construct(
        private SignupGate $gate,
        private BotCheck $bot,
        private OtpService $otp,
        private PersonalWorkspaces $workspaces,
        private LegalService $legal,
        private Notifier $notifier,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * @param  array{channel: string, email?: string|null, phone?: string|null, country_code?: string|null, name: string, password: string, locale?: string|null, marketing?: bool, terms_version: int, bot_token?: string|null}  $data
     */
    public function start(array $data, ?string $ip): OtpChallenge
    {
        $partner = $this->gate->partner();

        if (! $this->bot->passes($data['bot_token'] ?? null, $ip)) {
            throw IdentityException::botCheckFailed();
        }

        $locale = in_array($data['locale'] ?? null, LanguageRegistry::codes(), true)
            ? $data['locale']
            : (string) config('tenancy.defaults.default_locale');

        [$destination, $existing, $country] = $data['channel'] === 'sms'
            ? $this->phoneDestination($partner, (string) ($data['phone'] ?? ''), $data['country_code'] ?? null)
            : $this->emailDestination($partner, (string) ($data['email'] ?? ''));

        $challenge = $this->otp->issue(
            purpose: OtpChallenge::SIGNUP,
            channel: $data['channel'],
            destination: $destination,
            partner: $partner,
            details: [
                'name' => trim($data['name']),
                'password' => Hash::make($data['password']),
                'country_code' => $country ?? $data['country_code'] ?? null,
                'marketing' => (bool) ($data['marketing'] ?? false),
                'terms_version' => (int) $data['terms_version'],
            ],
            decoy: $existing !== null,
            ip: $ip,
            locale: $locale,
        );

        if ($existing !== null) {
            // Only the real owner of the address hears about it.
            $this->notifier->notify('identity.signup_attempt', [$existing], [], $partner, locale: $existing->locale ?? $locale);
        }

        return $challenge;
    }

    /**
     * @return array{user: User, workspace: Organization}
     */
    public function complete(string $challengeId, string $code): array
    {
        $challenge = $this->otp->verify($challengeId, OtpChallenge::SIGNUP, $code);
        $details = $challenge->details();
        $partner = Partner::query()->findOrFail($challenge->partner_id);
        $isPhone = $challenge->channel === 'sms';

        try {
            return DB::transaction(function () use ($challenge, $details, $partner, $isPhone) {
                $user = new User;
                $user->forceFill([
                    'name' => $details['name'],
                    'email' => $isPhone ? null : $details['to'],
                    'email_verified_at' => $isPhone ? null : now(),
                    'phone' => $isPhone ? $details['to'] : null,
                    'phone_verified_at' => $isPhone ? now() : null,
                    // Already hashed when the form was sent; the cast leaves a hash as it is.
                    'password' => $details['password'],
                    'locale' => $details['locale'],
                    'marketing_consent_at' => $details['marketing'] ? now() : null,
                ])->save();

                $workspace = $this->workspaces->create($user, $partner, $details['country_code'] ?? null, (string) $details['locale']);
                $this->acceptTerms($workspace, (int) $details['terms_version'], $user, (string) $details['locale']);

                $this->audit->record(
                    action: 'identity.signed_up',
                    target: $user,
                    new: ['channel' => $challenge->channel, 'workspace' => $workspace->getKey(), 'marketing' => (bool) $details['marketing']],
                    actor: $user,
                    organizationId: $workspace->getKey(),
                    partnerId: $partner->getKey(),
                );

                UserSignedUp::dispatch($user, $partner, $challenge->channel);

                return ['user' => $user, 'workspace' => $workspace];
            });
        } catch (UniqueConstraintViolationException) {
            // Someone took the address between the form and the code.
            throw IdentityException::addressTaken();
        }
    }

    /**
     * @return array{0: string, 1: User|null, 2: string|null}
     */
    private function emailDestination(Partner $partner, string $email): array
    {
        $email = Str::lower(trim($email));
        if ((bool) $this->rules->get('identity.block_disposable_email', $this->contexts->forPartner($partner)) && Addresses::isDisposable($email)) {
            throw IdentityException::disposableEmail();
        }

        return [$email, User::query()->where('email', $email)->first(), null];
    }

    /**
     * @return array{0: string, 1: User|null, 2: string|null}
     */
    private function phoneDestination(Partner $partner, string $phone, ?string $country): array
    {
        if (! $this->gate->smsAvailable($partner)) {
            throw IdentityException::smsUnavailable();
        }

        $e164 = PhoneNumber::normalize($phone, $country) ?? throw IdentityException::badPhone();
        $numberCountry = PhoneNumber::country($e164);
        if ($numberCountry === null || ! in_array($numberCountry, $this->gate->phoneCountries($partner), true)) {
            throw IdentityException::phoneCountryNotAllowed();
        }

        return [$e164, User::query()->where('phone', $e164)->first(), $numberCountry];
    }

    private function acceptTerms(Organization $workspace, int $version, User $user, string $locale): void
    {
        $terms = $this->legal->current($workspace->partner, 'terms');
        // Accepted at sign-up only if it is still the version shown; else the usual banner asks.
        if ($terms !== null && $terms->version === $version) {
            $this->legal->accept($workspace, 'terms', $version, $user, $locale);
        }
    }
}
