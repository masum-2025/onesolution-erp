<?php

namespace App\Platform\SupportAccess\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class SupportException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'support.errors.'.$this->errorCode;
    }

    public static function notFound(): self
    {
        return new self('grant_not_found', 404);
    }

    public static function alreadyOpen(): self
    {
        return new self('already_open', 422);
    }

    public static function tooLong(int $max): self
    {
        return new self('too_long', 422, ['max' => (string) $max], ['max' => $max]);
    }

    public static function notPending(): self
    {
        return new self('not_pending', 422);
    }

    public static function notActive(): self
    {
        return new self('not_active', 422);
    }

    public static function roleNotAllowed(): self
    {
        return new self('role_not_allowed', 403);
    }
}
