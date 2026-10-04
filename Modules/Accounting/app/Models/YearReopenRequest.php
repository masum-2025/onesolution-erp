<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone asks to reopen a closed fiscal year, with a reason; another person
 * approves (the closing entry is reversed) or rejects it.
 */
#[Fillable(['organization_id', 'fiscal_year_id', 'reason', 'status', 'requested_by'])]
class YearReopenRequest extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'acc_year_reopen_requests';

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }
}
