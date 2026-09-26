<?php

namespace App\Platform\Notifications\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class NotificationException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'notifications.errors.'.$this->errorCode;
    }

    public static function unknownNotification(): self
    {
        return new self('unknown_notification', 404);
    }

    public static function channelNotSupported(): self
    {
        return new self('channel_not_supported', 422);
    }

    /**
     * @param  list<string>  $unknown
     */
    public static function unknownPlaceholders(array $unknown, string $field): self
    {
        return new self('unknown_placeholders', 422, ['list' => implode(', ', array_map(fn ($name) => '{{ '.$name.' }}', $unknown))], ['field' => $field]);
    }

    public static function domainExists(): self
    {
        return new self('domain_exists', 422);
    }

    public static function customDomainNotAllowed(): self
    {
        return new self('custom_domain_not_allowed', 403);
    }

    public static function noMailDomain(): self
    {
        return new self('no_mail_domain', 404);
    }

    /**
     * @param  list<string>  $failed
     */
    public static function verificationFailed(array $failed): self
    {
        return new self('verification_failed', 422, ['checks' => implode(', ', $failed)], ['failed' => $failed]);
    }

    public static function noSmsSender(): self
    {
        return new self('no_sms_sender', 404);
    }

    public static function smsDisabled(): self
    {
        return new self('sms_disabled', 422);
    }

    public static function roleNotAllowed(): self
    {
        return new self('role_not_allowed', 403);
    }
}
