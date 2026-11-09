<?php

namespace Modules\OnlineOrders\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A change of where an order stands (its history), never changed once made. */
#[Fillable(['organization_id', 'order_id', 'from_status', 'to_status', 'note', 'actor_id', 'created_at'])]
class OrderEvent extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'online_order_events';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
