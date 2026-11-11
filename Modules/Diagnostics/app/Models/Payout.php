<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Commission paid to a referring doctor (append-only; a mistake is put right
 * by a reversal on the commission side, never by changing a payout).
 */
#[Fillable(['organization_id', 'unit_id', 'referrer_id', 'amount_minor', 'currency', 'method', 'paid_on', 'note', 'paid_by', 'op_id', 'created_at'])]
class Payout extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const METHODS = ['cash', 'bank', 'mobile'];

    public $timestamps = false;

    protected $table = 'diagnostics_payouts';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payouts are append-only.'));
        static::deleting(fn () => throw new LogicException('Payouts are append-only.'));
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_on' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }
}
