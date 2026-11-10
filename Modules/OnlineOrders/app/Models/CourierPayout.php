<?php

namespace Modules\OnlineOrders\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** SC-6: money a courier sent back for the cash it collected (written once, never changed). */
class CourierPayout extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    /** Where the money may arrive (each is a posting key in OrderPostings::METHOD_KEYS). */
    public const METHODS = ['bank', 'mobile', 'cash'];

    protected $table = 'online_order_courier_payouts';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'collected_minor' => 'integer',
            'received_minor' => 'integer',
            'fee_minor' => 'integer',
            'difference_minor' => 'integer',
            'payments_count' => 'integer',
            'paid_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }
}
