<?php

namespace Modules\OnlineOrders\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Money in for an order (payment) or back to its customer (refund); never changed once made. */
#[Fillable(['organization_id', 'order_id', 'kind', 'method', 'amount_minor', 'reference', 'recorded_by', 'op_id', 'created_at'])]
class OrderPayment extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const METHODS = ['cash', 'mobile', 'bank'];

    /** Paid by the customer through a payment link (SC-5a); only the gateway's confirmation records it. */
    public const ONLINE = 'online';

    public const UPDATED_AT = null;

    protected $table = 'online_order_payments';

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
