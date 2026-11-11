<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * What one order earns its referring doctor (kind "due"), or the line that
 * takes it back (kind "reversal", a negative amount with a reason). Append-only.
 */
#[Fillable(['organization_id', 'unit_id', 'referrer_id', 'order_id', 'kind', 'reverses_id', 'base_minor', 'percent', 'amount_minor', 'currency', 'earned_on', 'reason', 'created_by', 'created_at'])]
class Commission extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const DUE = 'due';

    public const REVERSAL = 'reversal';

    public $timestamps = false;

    protected $table = 'diagnostics_commissions';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Commissions are append-only.'));
        static::deleting(fn () => throw new LogicException('Commissions are append-only.'));
    }

    protected function casts(): array
    {
        return ['base_minor' => 'integer', 'percent' => 'integer', 'amount_minor' => 'integer', 'earned_on' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }
}
