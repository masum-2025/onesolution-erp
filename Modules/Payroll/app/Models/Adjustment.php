<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A one-off addition (bonus, arrears) or deduction (advance) for one employee in a draft run. */
#[Fillable(['organization_id', 'run_id', 'employee_id', 'kind', 'label', 'amount_minor', 'taxable', 'created_by'])]
class Adjustment extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pay_adjustments';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'taxable' => 'boolean'];
    }
}
