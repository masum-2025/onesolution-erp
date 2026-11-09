<?php

namespace Modules\OnlineOrders\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Online order errors: a translated message (online_orders::orders.errors.*)
 * that says what to do next, and a stable code.
 */
class OnlineOrdersException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'online_orders::orders.errors.'.$this->errorCode;
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

    public static function warehouseUnusable(): self
    {
        return new self('warehouse_unusable', 422);
    }

    public static function itemNotSold(string $item): self
    {
        return new self('item_not_sold', 422, ['item' => $item]);
    }

    public static function notEnoughStock(string $item, string $available): self
    {
        return new self('not_enough_stock', 409, ['item' => $item, 'available' => $available]);
    }

    public static function codNotAllowed(): self
    {
        return new self('cod_not_allowed', 422);
    }

    public static function unknownZone(string $zone): self
    {
        return new self('unknown_zone', 422, ['zone' => $zone]);
    }

    public static function discountLimit(string $percent): self
    {
        return new self('discount_limit', 403, ['percent' => $percent]);
    }

    public static function wrongStatus(string $action, string $status): self
    {
        return new self('wrong_status', 409, ['action' => $action, 'status' => $status]);
    }

    public static function needsSupervisor(): self
    {
        return new self('needs_supervisor', 403);
    }

    public static function prepaidNotPaid(string $due): self
    {
        return new self('prepaid_not_paid', 409, ['due' => $due]);
    }

    public static function overpaid(string $due): self
    {
        return new self('overpaid', 422, ['due' => $due]);
    }

    public static function nothingDue(): self
    {
        return new self('nothing_due', 409);
    }

    public static function noPayLink(): self
    {
        return new self('no_pay_link', 409);
    }

    public static function refundMethodMissing(string $amount): self
    {
        return new self('refund_method_missing', 422, ['amount' => $amount]);
    }

    public static function returnTooLate(int $days): self
    {
        return new self('return_too_late', 409, ['days' => (string) $days]);
    }

    /** SC-5b: the courier is not one this company may use (online_orders.couriers, its country). */
    public static function courierNotAllowed(): self
    {
        return new self('courier_not_allowed', 422);
    }

    /** SC-5b: the courier account is switched off. */
    public static function courierOff(): self
    {
        return new self('courier_off', 409);
    }

    /** SC-5b: the order was not booked with a courier, so there is nobody to ask. */
    public static function notBooked(): self
    {
        return new self('not_booked', 409);
    }

    /** SC-5b: the courier refused or could not be reached (reason: unreachable, bad_credentials, refused, bad_answer). */
    public static function courierFailed(string $reason, ?string $detail): self
    {
        return new self("courier_{$reason}", $reason === 'unreachable' ? 503 : 422, ['detail' => $detail ?? '']);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }
}
