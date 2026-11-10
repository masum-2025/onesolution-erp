<?php

namespace Modules\Pos\Contracts;

use App\Platform\Tenancy\Models\Organization;
use Modules\Pos\Models\Register;

/**
 * Another module's say over a sale's lines before it is priced (Pharmacy:
 * never above MRP, never an expired batch, controlled medicines only with a
 * prescription). Bound with the container tag "pos.sale_guards"; a guard
 * whose module is off for the company returns the lines untouched.
 *
 * A guard may split a line into lines per batch (batch_number). Online it
 * throws (a ValidationException or the module's own exception) to refuse a
 * sale; for a sale already made offline it returns review reasons instead,
 * so the sale is kept and marked for a supervisor.
 *
 * $context is what the guard's own module passed to Sales::sell() under
 * "guard" (never from the till's request).
 */
interface SaleGuard
{
    public const TAG = 'pos.sale_guards';

    /**
     * @param  array<string, array<string, mixed>>  $items  Inventory's items by id (as Stock::items()).
     * @param  list<array{item_id: string, quantity_milli: int, discount_minor?: int, batch_number?: string|null}>  $lines
     * @param  array<string, mixed>  $context
     * @return array{lines: list<array{item_id: string, quantity_milli: int, discount_minor?: int, batch_number?: string|null}>, reviews: list<string>}
     */
    public function prepare(Organization $company, Register $register, array $items, array $lines, array $context, bool $offline): array;
}
