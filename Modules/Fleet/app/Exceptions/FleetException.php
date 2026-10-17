<?php

namespace Modules\Fleet\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Fleet errors: a translated message (fleet::messages.errors.*) that says what to do next, and a stable code.
 */
class FleetException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'fleet::messages.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function notFound(string $what): self
    {
        return new self("{$what}_not_found", 404);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }

    public static function noCurrency(): self
    {
        return new self('no_currency', 422);
    }

    /** A link to something that is not the company's (or not this unit's), named by its field. */
    public static function badLink(string $field): self
    {
        return new self('bad_link', 422, [], ['field' => $field]);
    }

    /** Other records still use this one. */
    public static function inUse(): self
    {
        return new self('in_use', 409);
    }
}
