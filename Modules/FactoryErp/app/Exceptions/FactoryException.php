<?php

namespace Modules\FactoryErp\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Factory errors: a translated message (factory_erp::factory.errors.*) that
 * says what to do next, and a stable code.
 */
class FactoryException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'factory_erp::factory.errors.'.$this->errorCode;
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

    /** The company has no currency set (money fields need one). */
    public static function noCurrency(): self
    {
        return new self('no_currency', 422);
    }

    public static function wrongStatus(): self
    {
        return new self('wrong_status', 409);
    }

    /** A size or colour taken out of a style while orders still use it. */
    public static function inUse(string $what): self
    {
        return new self("{$what}_in_use", 409, ['what' => $what]);
    }

    /** Buyers come from CRM: it must be on and the contact one of its buyers. */
    public static function notABuyer(): self
    {
        return new self('not_a_buyer', 422, [], ['field' => 'buyer_id']);
    }

    /** An order line names a size or colour the style does not have. */
    public static function notInStyle(string $value): self
    {
        return new self('not_in_style', 422, ['value' => $value], ['field' => 'lines']);
    }

    /** An order is confirmed only with pieces in it. */
    public static function noPieces(): self
    {
        return new self('no_pieces', 422, [], ['field' => 'lines']);
    }

    /** A BOM line names something that is not an active Inventory item. */
    public static function notAnItem(): self
    {
        return new self('not_an_item', 422, [], ['field' => 'lines']);
    }
}
