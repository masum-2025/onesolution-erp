<?php

namespace Modules\Inventory\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Inventory errors: a translated message (inventory::inventory.errors.*)
 * that says what to do next, and a stable code.
 */
class InventoryException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'inventory::inventory.errors.'.$this->errorCode;
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

    public static function notStockItem(string $item): self
    {
        return new self('not_stock_item', 422, ['item' => $item]);
    }

    public static function insufficientStock(string $item, string $available): self
    {
        return new self('insufficient_stock', 409, ['item' => $item, 'available' => $available]);
    }

    public static function batchNeeded(string $item): self
    {
        return new self('batch_needed', 422, ['item' => $item]);
    }

    public static function wrongStatus(string $status): self
    {
        return new self('wrong_status', 409, ['status' => $status]);
    }

    public static function ownDocument(): self
    {
        return new self('own_document', 403);
    }

    public static function sameWarehouse(): self
    {
        return new self('same_warehouse', 422);
    }

    public static function inactive(string $what): self
    {
        return new self("{$what}_inactive", 422);
    }

    public static function unknownStep(): self
    {
        return new self('unknown_step', 404);
    }

    public static function notBillable(): self
    {
        return new self('not_billable', 409);
    }

    public static function countOpen(): self
    {
        return new self('count_open', 409);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }
}
