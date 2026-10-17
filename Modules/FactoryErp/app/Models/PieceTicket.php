<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An operator's pieces of one operation of an order on a day, at the piece rate then, and what it pays. Append-only; voided with a reason.
 */
#[Fillable(['organization_id', 'unit_id', 'employee_id', 'order_id', 'operation_id', 'operation_name', 'worked_on', 'pieces', 'rate_minor', 'rate_per', 'amount_minor', 'currency', 'created_by', 'voided_at', 'voided_by', 'void_reason', 'op_id', 'version'])]
class PieceTicket extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_piece_tickets';

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['worked_on' => 'immutable_date', 'pieces' => 'integer', 'rate_minor' => 'integer', 'rate_per' => 'integer', 'amount_minor' => 'integer', 'voided_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
