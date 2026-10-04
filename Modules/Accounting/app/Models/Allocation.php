<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * How much of a settlement (or of a credit note / vendor credit) pays one
 * document. Append-only: when its source is voided it is marked void, never
 * changed or removed.
 */
#[Fillable(['organization_id', 'settlement_id', 'credit_document_id', 'document_id', 'amount_minor', 'allocated_on', 'created_by'])]
class Allocation extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_allocations';

    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (Allocation $allocation) {
            if (array_diff(array_keys($allocation->getDirty()), ['voided_at']) !== []) {
                throw new LogicException('Allocations are never changed; they are voided with their source.');
            }
        });
        static::deleting(fn () => throw new LogicException('Allocations are never removed.'));
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'allocated_on' => 'immutable_date', 'voided_at' => 'immutable_datetime'];
    }
}
