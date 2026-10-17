<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An end-line check of a line's pieces or an order's final inspection (sample from the AQL table, pass or fail); voided with a reason, never deleted.
 */
#[Fillable(['organization_id', 'unit_id', 'order_id', 'line_id', 'kind', 'inspected_on', 'hour', 'checked', 'defects', 'lot_size', 'aql', 'accept', 'result', 'note', 'created_by', 'voided_at', 'voided_by', 'void_reason', 'op_id', 'version'])]
class Inspection extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['endline', 'final'];

    protected $attributes = ['version' => 1];

    protected $table = 'fac_inspections';

    protected function casts(): array
    {
        return ['inspected_on' => 'immutable_date', 'hour' => 'integer', 'checked' => 'integer', 'defects' => 'integer', 'lot_size' => 'integer', 'accept' => 'integer', 'voided_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
