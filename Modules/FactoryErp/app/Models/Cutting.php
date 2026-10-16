<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One lay cut for an order (pieces per size and colour in CuttingLine); voided with a reason, never deleted.
 */
#[Fillable(['organization_id', 'unit_id', 'order_id', 'lay_number', 'cut_on', 'pieces', 'note', 'created_by', 'voided_at', 'voided_by', 'void_reason', 'op_id', 'version'])]
class Cutting extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_cuttings';

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['cut_on' => 'immutable_date', 'lay_number' => 'integer', 'pieces' => 'integer', 'voided_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
