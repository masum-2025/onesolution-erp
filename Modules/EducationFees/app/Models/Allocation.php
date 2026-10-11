<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * How much of a receipt (or of the advance) went to a bill; negative rows undo earlier ones.
 */
#[Fillable(['organization_id', 'bill_id', 'kind', 'receipt_id', 'advance_id', 'reverses_id', 'amount_minor', 'created_by'])]
class Allocation extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fee_allocations';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
