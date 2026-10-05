<?php

namespace Modules\Inventory\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** An item in a count: expected when it started, counted, and the difference's value once posted. */
#[Fillable(['organization_id', 'count_id', 'item_id', 'expected_milli', 'counted_milli', 'value_minor'])]
class StockCountLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'inv_count_lines';

    protected function casts(): array
    {
        return [
            'expected_milli' => 'integer',
            'counted_milli' => 'integer',
            'value_minor' => 'integer',
        ];
    }
}
