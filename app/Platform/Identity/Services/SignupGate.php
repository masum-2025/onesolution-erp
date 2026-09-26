<?php

namespace App\Platform\Identity\Services;

use App\Platform\Identity\Contracts\BotCheck;
use App\Platform\Identity\Exceptions\IdentityException;
use App\Platform\Legal\Services\LegalService;
use App\Platform\Notifications\Services\SmsSender;
use App\Platform\Partners\HostContext;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Partner;

/**
 * Who takes a self-serve sign-up at this address: the partner of the
 * address (the house partner on the platform's own address), where its
 * rule b2c.self_signup_allowed is on. A client's own address never takes
 * sign-ups: its people are added by the client.
 */
class SignupGate
{
    public function __construct(
        private HostContext $host,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private SmsSender $sms,
        private BotCheck $bot,
        private LegalService $legal,
    ) {}

    /**
     * The partner of this address, whether or not it takes sign-ups (recovery works everywhere).
     */
    public function addressPartner(): ?Partner
    {
        return $this->host->partner() ?? Partner::query()->where('is_house', true)->first();
    }

    public function allowed(): bool
    {
        $partner = $this->addressPartner();

        return $partner !== null
            && $partner->isActive()
            && $this->bot->publicConfig()['driver'] !== 'blocked'
            && $this->host->client() === null
            && (bool) $this->rules->get('b2c.self_signup_allowed', $this->contexts->forPartner($partner));
    }

    public function partner(): Partner
    {
        if (! $this->allowed()) {
            throw IdentityException::signupClosed();
        }

        return $this->addressPartner();
    }

    public function smsAvailable(?Partner $partner): bool
    {
        return $this->sms->enabled($partner);
    }

    /**
     * @return list<string>
     */
    public function phoneCountries(?Partner $partner): array
    {
        $context = $partner === null ? $this->contexts->platform() : $this->contexts->forPartner($partner);

        return array_values(array_intersect(
            (array) $this->rules->get('identity.allowed_phone_countries', $context),
            array_keys((array) config('identity.phone_countries')),
        ));
    }

    /**
     * What the sign-up, sign-in and recovery screens need (no secrets).
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $partner = $this->addressPartner();
        $sms = $this->smsAvailable($partner);
        $terms = $this->legal->current($partner, 'terms');
        $privacy = $this->legal->current($partner, 'privacy');

        return [
            'allowed' => $this->allowed(),
            'channels' => $sms ? ['mail', 'sms'] : ['mail'],
            // Phone sign-in and recovery need SMS as well.
            'phone' => $sms,
            'phone_countries' => array_map(fn (string $code) => [
                'code' => $code,
                'dial' => config("identity.phone_countries.{$code}.dial"),
            ], $this->phoneCountries($partner)),
            'default_country' => config('tenancy.defaults.country_code'),
            'bot' => $this->bot->publicConfig(),
            'legal' => [
                'terms_version' => $terms?->version,
                'privacy_version' => $privacy?->version,
            ],
            'otp_length' => (int) config('identity.otp.length'),
        ];
    }
}
