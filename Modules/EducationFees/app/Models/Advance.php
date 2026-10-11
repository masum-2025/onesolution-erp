<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A movement of a student's money held for later: in (positive) or out (negative).
 */
#[Fillable(['organization_id', 'unit_id', 'student_id', 'kind', 'amount_minor', 'receipt_id', 'bill_id', 'refund_id', 'note', 'created_by'])]
class Advance extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fee_advances';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
