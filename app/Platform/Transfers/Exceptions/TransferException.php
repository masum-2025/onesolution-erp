<?php

namespace App\Platform\Transfers\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class TransferException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'transfers.errors.'.$this->errorCode;
    }

    public static function invalidCode(): self
    {
        // Wrong, expired, used or revoked: the same answer, so codes cannot be probed.
        return new self('invalid_code', 422);
    }

    public static function samePartner(): self
    {
        return new self('same_partner', 422);
    }

    public static function destinationInactive(): self
    {
        return new self('destination_inactive', 422);
    }

    public static function alreadyOpen(): self
    {
        return new self('already_open', 422);
    }

    public static function notOpen(): self
    {
        return new self('not_open', 422);
    }

    public static function ownersOnly(): self
    {
        return new self('owners_only', 403);
    }

    public static function notFound(): self
    {
        return new self('not_found', 404);
    }

    public static function blocked(string $problems): self
    {
        return new self('blocked', 422, ['problems' => $problems]);
    }

    public static function consentRequired(): self
    {
        return new self('consent_required', 422);
    }

    public static function roleNotAllowed(): self
    {
        return new self('role_not_allowed', 403);
    }
}
