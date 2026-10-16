<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One item of an issue or return: thousandths of its unit (above zero) and its cost.
 */
#[Fillable(['organization_id', 'issue_id', 'order_id', 'item_id', 'quantity_milli', 'cost_minor'])]
class IssueLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_issue_lines';

    protected function casts(): array
    {
        return ['quantity_milli' => 'integer', 'cost_minor' => 'integer'];
    }
}
