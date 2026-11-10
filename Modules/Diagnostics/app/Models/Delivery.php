<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A report handed over (printed, given by hand; links and SMS come in H-2b).
 */
#[Fillable(['organization_id', 'order_id', 'channel', 'sent_by', 'sent_at'])]
class Delivery extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const CHANNELS = ['print', 'hand'];

    public $timestamps = false;

    protected $table = 'diagnostics_deliveries';

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
