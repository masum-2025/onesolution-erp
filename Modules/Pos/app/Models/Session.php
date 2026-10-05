<?php

namespace Modules\Pos\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A shift at a counter:
 *
 *   open (a float) -> close (cash counted) -> closed            (difference within the rule)
 *                                         -> pending_review -> review (a supervisor) -> closed
 */
#[Fillable(['organization_id', 'register_id', 'status', 'opened_by', 'opened_at', 'opening_float_minor', 'currency_code', 'version'])]
class Session extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const OPEN = 'open';

    public const PENDING = 'pending_review';

    public const CLOSED = 'closed';

    protected $table = 'pos_sessions';

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'opening_float_minor' => 'integer',
            'expected_cash_minor' => 'integer',
            'counted_cash_minor' => 'integer',
            'variance_minor' => 'integer',
            'sales_count' => 'integer',
            'sales_minor' => 'integer',
            'returns_minor' => 'integer',
            'tax_minor' => 'integer',
            'version' => 'integer',
        ];
    }
}
