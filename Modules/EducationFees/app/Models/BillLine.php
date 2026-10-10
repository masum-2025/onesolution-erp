<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of a bill: the amount, its discount (with how it was found), tax, and what is owed.
 */
#[Fillable(['organization_id', 'bill_id', 'head_id', 'amount_minor', 'discount_minor', 'tax_minor', 'due_minor', 'tax_code_id', 'basis'])]
class BillLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fee_bill_lines';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'discount_minor' => 'integer', 'tax_minor' => 'integer', 'due_minor' => 'integer', 'basis' => 'array'];
    }
}
