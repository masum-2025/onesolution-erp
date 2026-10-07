<?php

namespace Modules\Crm\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Customer relations errors: a translated message (crm::crm.errors.*) that
 * says what to do next, and a stable code.
 */
class CrmException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'crm::crm.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function noCurrency(): self
    {
        return new self('no_currency', 409);
    }

    public static function notFound(string $what): self
    {
        return new self("{$what}_not_found", 404);
    }

    public static function wrongStatus(string $status): self
    {
        return new self('wrong_status', 409, ['status' => $status]);
    }

    public static function anonymized(): self
    {
        return new self('anonymized', 409);
    }

    public static function accountingOff(): self
    {
        return new self('accounting_off', 409);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }
}
