<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One test of an order: ordered (waiting for its sample), collected (sample
 * taken, waiting for results), resulted (waiting for verification) or
 * verified. "amendment" counts result submissions; results after a
 * verification are a new amendment and need verifying again.
 */
#[Fillable(['organization_id', 'order_id', 'test_id', 'status', 'sample_id', 'amendment', 'resulted_by', 'resulted_at', 'send_back_note', 'version'])]
class OrderTest extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const ORDERED = 'ordered';

    public const COLLECTED = 'collected';

    public const RESULTED = 'resulted';

    public const VERIFIED = 'verified';

    protected $table = 'diagnostics_order_tests';

    protected $attributes = ['version' => 1, 'status' => self::ORDERED, 'amendment' => 0];

    protected function casts(): array
    {
        return ['amendment' => 'integer', 'version' => 'integer', 'resulted_at' => 'immutable_datetime'];
    }
}
