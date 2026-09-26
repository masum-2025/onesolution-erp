<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Notifications\Contracts\SmsGateway;
use App\Platform\Notifications\Exceptions\NotificationException;
use App\Platform\Notifications\Models\PartnerSmsSender;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Partner;

/**
 * Sends one SMS under the partner's approved sender ID (else the platform's
 * default), only where SMS is turned on for the partner.
 */
class SmsSender
{
    public function __construct(private SmsGateway $gateway, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function enabled(?Partner $partner): bool
    {
        return (bool) $this->rules->get('notifications.sms_enabled', $partner === null ? $this->contexts->platform() : $this->contexts->forPartner($partner));
    }

    public function senderId(?Partner $partner): string
    {
        $own = $partner === null ? null : PartnerSmsSender::query()->where('partner_id', $partner->getKey())->first();

        return $own?->isApproved() ? $own->sender_id : (string) config('notifications.sms.default_sender');
    }

    /**
     * @return string The sender ID used.
     */
    public function send(?Partner $partner, string $to, string $text): string
    {
        if (! $this->enabled($partner)) {
            throw NotificationException::smsDisabled();
        }

        $sender = $this->senderId($partner);
        $this->gateway->send($to, $sender, $text);

        return $sender;
    }
}
