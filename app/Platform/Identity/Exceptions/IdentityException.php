<?php

namespace App\Platform\Identity\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;
use NumberFormatter;

class IdentityException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'identity.errors.'.$this->errorCode;
    }

    public static function signupClosed(): self
    {
        return new self('signup_closed', 403);
    }

    public static function botCheckFailed(): self
    {
        return new self('bot_check_failed', 422, [], ['field' => 'bot_token']);
    }

    public static function disposableEmail(): self
    {
        return new self('disposable_email', 422, [], ['field' => 'email']);
    }

    public static function badPhone(): self
    {
        return new self('bad_phone', 422, [], ['field' => 'phone']);
    }

    public static function phoneCountryNotAllowed(): self
    {
        return new self('phone_country_not_allowed', 422, [], ['field' => 'phone']);
    }

    public static function smsUnavailable(): self
    {
        return new self('sms_unavailable', 422, [], ['field' => 'phone']);
    }

    public static function tooManyCodes(int $minutes): self
    {
        return new self('too_many_codes', 429, ['minutes' => self::number($minutes)], ['retry_after_minutes' => $minutes]);
    }

    public static function resendTooSoon(int $seconds): self
    {
        return new self('resend_too_soon', 429, ['seconds' => self::number($seconds)], ['retry_after_seconds' => $seconds]);
    }

    public static function codeWrong(int $left): self
    {
        return new self('code_wrong', 422, ['left' => self::number($left)], ['field' => 'code', 'attempts_left' => $left]);
    }

    public static function codeExpired(): self
    {
        return new self('code_expired', 422, [], ['field' => 'code', 'restart' => true]);
    }

    public static function addressTaken(): self
    {
        return new self('address_taken', 409);
    }

    public static function cooldown(string $until): self
    {
        return new self('cooldown', 423, ['until' => $until]);
    }

    public static function wrongPassword(): self
    {
        return new self('wrong_password', 422, [], ['field' => 'current_password']);
    }

    public static function lastSignInMethod(): self
    {
        return new self('last_sign_in_method', 422);
    }

    public static function sessionNotFound(): self
    {
        return new self('session_not_found', 404);
    }

    /** Numbers in the reader's own digits (e.g. Bangla ৩). */
    private static function number(int $value): string
    {
        return (string) (new NumberFormatter(app()->getLocale(), NumberFormatter::DECIMAL))->format($value);
    }

    public static function noPersonalWorkspace(): self
    {
        return new self('no_personal_workspace', 404);
    }
}
