<?php

namespace App\Platform\Notifications\Contracts;

/**
 * Sends one SMS through a provider. Swappable per installation (log driver
 * until a provider is added; SSL Wireless, Twilio and others implement this).
 */
interface SmsGateway
{
    /**
     * @return string The provider's message id.
     */
    public function send(string $to, string $senderId, string $text): string;
}
