<?php

namespace Modules\Inventory\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A stock count of a warehouse (or one category in it):
 *
 *   counting (expected quantities taken at the start; counted entered) -> submit
 *     -> pending_approval -> approve (someone else; the differences are posted) -> posted
 *     -> reject -> counting;  counting -> cancel
 */
#[Fillable(['organization_id', 'number', 'warehouse_id', 'category_id', 'status', 'counted_on', 'currency_code', 'created_by', 'version'])]
class StockCount extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const COUNTING = 'counting';

    public const PENDING = 'pending_approval';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    protected $table = 'inv_counts';

    protected function casts(): array
    {
        return [
            'counted_on' => 'immutable_date',
            'variance_value_minor' => 'integer',
            'posted_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
