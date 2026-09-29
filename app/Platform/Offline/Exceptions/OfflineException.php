<?php

namespace App\Platform\Offline\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Offline mode errors (Phase 7): lang/{locale}/offline.php "errors.*".
 * A device that must clear its data gets "wipe": true in the answer.
 */
class OfflineException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'offline.errors.'.$this->errorCode;
    }

    public static function deviceNotFound(): self
    {
        return new self('device_not_found', 404);
    }

    public static function notAllowed(): self
    {
        return new self('not_allowed', 403);
    }

    public static function wrongOrganization(): self
    {
        return new self('wrong_organization', 403);
    }

    public static function leaseInvalid(): self
    {
        return new self('lease_invalid', 401, [], ['renew_lease' => true]);
    }

    public static function leaseExpired(): self
    {
        return new self('lease_expired', 401, [], ['renew_lease' => true]);
    }

    public static function tooMany(int $max): self
    {
        return new self('too_many', 422, ['max' => (string) $max], ['max' => $max]);
    }

    public static function organizationInactive(): self
    {
        return new self('organization_inactive', 403);
    }

    public static function quarantineNotFound(): self
    {
        return new self('quarantine_not_found', 404);
    }

    public static function alreadyDecided(): self
    {
        return new self('already_decided', 422);
    }
}
