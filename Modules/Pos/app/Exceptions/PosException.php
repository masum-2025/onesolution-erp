<?php

namespace Modules\Pos\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Point of sale errors: a translated message (pos::pos.errors.*) that says
 * what to do next, and a stable code.
 */
class PosException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'pos::pos.errors.'.$this->errorCode;
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

    public static function registerInactive(): self
    {
        return new self('register_inactive', 409);
    }

    public static function sessionOpen(): self
    {
        return new self('session_open', 409);
    }

    public static function noOpenSession(): self
    {
        return new self('no_open_session', 409);
    }

    public static function sessionNotOpen(): self
    {
        return new self('session_not_open', 409);
    }

    public static function notPending(): self
    {
        return new self('not_pending', 409);
    }

    public static function ownSession(): self
    {
        return new self('own_session', 403);
    }

    public static function underpaid(string $owed): self
    {
        return new self('underpaid', 422, ['owed' => $owed]);
    }

    public static function changeWithoutCash(): self
    {
        return new self('change_without_cash', 422);
    }

    public static function methodNotTaken(string $method): self
    {
        return new self('method_not_taken', 422, ['method' => $method]);
    }

    public static function discountLimit(string $percent): self
    {
        return new self('discount_limit', 403, ['percent' => $percent]);
    }

    public static function itemNotSold(string $item): self
    {
        return new self('item_not_sold', 422, ['item' => $item]);
    }

    public static function returnTooMuch(string $item): self
    {
        return new self('return_too_much', 422, ['item' => $item]);
    }

    public static function returnTooLate(int $days): self
    {
        return new self('return_too_late', 409, ['days' => (string) $days]);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }
}
