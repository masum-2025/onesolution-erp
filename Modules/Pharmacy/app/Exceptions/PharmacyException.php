<?php

namespace Modules\Pharmacy\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Pharmacy errors: a translated message (pharmacy::messages.errors.*) that says what to do next, and a stable code.
 */
class PharmacyException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'pharmacy::messages.errors.'.$this->errorCode;
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

    /** The item sells above its MRP (rule pharmacy.block_above_mrp). */
    public static function aboveMrp(string $medicine): self
    {
        return new self('above_mrp', 422, ['medicine' => $medicine]);
    }

    /** A controlled medicine without the prescription's details (rule pharmacy.controlled_requires_prescription). */
    public static function prescriptionNeeded(string $medicine): self
    {
        return new self('prescription_needed', 422, ['medicine' => $medicine]);
    }

    /** Only expired batches are left to sell (rule pharmacy.block_expired_sale). */
    public static function expiredStock(string $medicine): self
    {
        return new self('expired_stock', 409, ['medicine' => $medicine]);
    }

    /** A loose piece of a medicine sold by the strip (rule pharmacy.sell_loose_pieces). */
    public static function loosePieces(string $medicine): self
    {
        return new self('loose_pieces', 422, ['medicine' => $medicine]);
    }

    /** This medicine has no such pack (no strip or box size set). */
    public static function noPack(string $medicine): self
    {
        return new self('no_pack', 422, ['medicine' => $medicine]);
    }

    /** Controlled medicines are not sold offline. */
    public static function controlledOffline(): self
    {
        return new self('controlled_offline', 422);
    }

    /** The person who made it cannot approve it too. */
    public static function ownApproval(): self
    {
        return new self('own_approval', 403);
    }

    /** It was decided already. */
    public static function decided(): self
    {
        return new self('decided', 409);
    }

    /** More than the batch holds. */
    public static function batchShort(string $medicine, string $batch): self
    {
        return new self('batch_short', 409, ['medicine' => $medicine, 'batch' => $batch]);
    }

    /** Selling from a prescription needs Outpatient on for the company. */
    public static function prescriptionsOff(): self
    {
        return new self('prescriptions_off', 403);
    }

    /** Other records still use this one. */
    public static function inUse(): self
    {
        return new self('in_use', 409);
    }
}
