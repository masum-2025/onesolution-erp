<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A late fine added to a bill, or a waiver (negative): append-only.
 */
#[Fillable(['organization_id', 'bill_id', 'kind', 'amount_minor', 'applied_on', 'reason', 'created_by'])]
class Fine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fee_fines';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'applied_on' => 'immutable_date'];
    }
}
