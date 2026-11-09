<?php

namespace Modules\OnlineOrders\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * SC-5b: a company's own account at a courier. The API keys are encrypted
 * at rest, hidden from serialization and only read by the courier's
 * driver; the webhook token is kept as a hash. Nothing is mass assignable.
 */
class Courier extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'online_order_couriers';

    protected $guarded = ['*'];

    protected $hidden = ['credentials', 'webhook_token_hash'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
