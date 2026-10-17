<?php

namespace Modules\FactoryErp\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;
use Illuminate\Support\Number;

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

    /** Materials go to an order only if its style's bill of materials has them. */
    public static function notInBom(): self
    {
        return new self('not_in_bom', 422, [], ['field' => 'lines']);
    }

    /**
     * More than the order needs: refused (rule factory_erp.over_issue = block),
     * or asked again (warn) until the person says so (allow_over).
     *
     * @param  list<array{item_id: string, need_milli: int, issued_milli: int, quantity_milli: int}>  $items
     */
    public static function overIssue(array $items, bool $blocked): self
    {
        return new self($blocked ? 'over_issue_blocked' : 'over_issue', 422, [], ['items' => $items]);
    }

    /** A return of more than the order still holds of an item. */
    public static function tooMuchBack(): self
    {
        return new self('too_much_back', 422, [], ['field' => 'lines']);
    }

    /** More pieces cut of a size and colour than ordered plus the allowance (rule factory_erp.over_cut_bp). */
    public static function overCut(string $size, string $color, int $allowed): self
    {
        return new self('over_cut', 422, ['size' => $size, 'color' => $color, 'allowed' => Number::format($allowed, locale: app()->getLocale())], ['field' => 'lines', 'allowed' => $allowed]);
    }

    /** A day after today at the company. */
    public static function futureDate(string $field): self
    {
        return new self('future_date', 422, [], ['field' => $field]);
    }

    /** A BOM line names something that is not an active Inventory item. */
    public static function notAnItem(string $field = 'lines'): self
    {
        return new self('not_an_item', 422, [], ['field' => $field]);
    }

    /** Finished pieces need the style's finished item (an Inventory item that keeps stock). */
    public static function noFinishedItem(): self
    {
        return new self('no_finished_item', 422, [], ['field' => 'finished_item_id']);
    }

    /** More good pieces sewn than cut, or received than sewn, of a size and colour. */
    public static function overStage(string $stage, string $size, string $color, int $left): self
    {
        return new self("over_{$stage}", 422, ['size' => $size, 'color' => $color, 'left' => Number::format(max(0, $left), locale: app()->getLocale())], ['field' => 'lines', 'left' => max(0, $left)]);
    }

    /** The order's last final inspection did not pass (or there is none) where the company needs one to ship. */
    public static function finalNotPassed(): self
    {
        return new self('final_not_passed', 409);
    }

    /** A sewing line that is not this factory's, or switched off. */
    public static function lineOff(): self
    {
        return new self('line_off', 422, [], ['field' => 'line_id']);
    }

    /** An hour is given where the company writes output a day at a time, or none where it writes by the hour. */
    public static function hourRule(bool $needed): self
    {
        return new self($needed ? 'hour_needed' : 'hour_not_used', 422, [], ['field' => 'hour']);
    }
}
