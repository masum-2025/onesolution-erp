<?php

namespace App\Platform\Partners\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class PartnerException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'partners.errors.'.$this->errorCode;
    }

    public static function roleNotAllowed(): self
    {
        return new self('role_not_allowed', 403);
    }

    public static function contrast(string $field, string $problem): self
    {
        return new self("contrast_{$problem}", 422, ['field' => __("partners.fields.{$field}")], ['field' => $field]);
    }

    public static function poweredByLocked(): self
    {
        return new self('powered_by_locked', 403);
    }

    public static function hostTaken(): self
    {
        return new self('host_taken', 422);
    }

    public static function hostReserved(): self
    {
        return new self('host_reserved', 422);
    }

    public static function domainNotFound(): self
    {
        return new self('domain_not_found', 404);
    }

    /**
     * @param  list<string>  $found  TXT values seen at the name (safe to show: they are public DNS).
     */
    public static function verificationFailed(string $name, string $expected, array $found): self
    {
        return new self('verification_failed', 422, ['name' => $name, 'value' => $expected], ['record' => ['name' => $name, 'value' => $expected], 'found' => $found]);
    }

    public static function clientNotFound(): self
    {
        return new self('client_not_found', 404);
    }

    public static function notTopLevel(): self
    {
        return new self('not_top_level', 422);
    }

    public static function clientLimitReached(int $max): self
    {
        return new self('client_limit_reached', 422, ['max' => (string) $max], ['max' => $max]);
    }

    public static function countryNotAllowed(string $country): self
    {
        return new self('country_not_allowed', 422, ['country' => $country]);
    }

    public static function moduleNotOffered(string $module): self
    {
        return new self('module_not_offered', 422, ['module' => $module]);
    }
}
