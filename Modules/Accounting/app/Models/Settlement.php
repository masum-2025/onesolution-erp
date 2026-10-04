<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Accounting\Enums\SettlementStatus;
use Modules\Accounting\Enums\SettlementType;

/**
 * Money received from a customer or paid to a vendor. A money record:
 * never deleted and, once posted, never changed except how much of it is
 * allocated and whether it was voided.
 */
#[Fillable([
    'organization_id', 'type', 'party_id', 'settled_on', 'account_id', 'amount_minor', 'currency_code',
    'reference', 'memo', 'status', 'requested_allocations', 'op_id', 'created_by', 'version',
])]
class Settlement extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_settlements';

    private const WRITABLE_WHEN_POSTED = ['status', 'allocated_minor', 'voided_at', 'void_reason', 'version', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (Settlement $settlement) {
            if (in_array($settlement->getOriginal('status'), [SettlementStatus::Posted, SettlementStatus::Void], true)
                && array_diff(array_keys($settlement->getDirty()), self::WRITABLE_WHEN_POSTED) !== []) {
                throw new LogicException('Posted money records never change; void them instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Money records are never removed.'));
    }

    protected function casts(): array
    {
        return [
            'type' => SettlementType::class,
            'status' => SettlementStatus::class,
            'settled_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'allocated_minor' => 'integer',
            'requested_allocations' => 'array',
            'posted_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /** Received or paid but not yet allocated to a document (an advance). */
    public function unallocated(): int
    {
        return $this->amount_minor - $this->allocated_minor;
    }
}
