<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee's basic and structure from a day (to a day; open = until a
 * new one starts). History is kept: a new salary closes the one before.
 */
#[Fillable(['organization_id', 'employee_id', 'structure_id', 'basic_minor', 'effective_from', 'effective_to', 'reason', 'created_by'])]
class Salary extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pay_salaries';

    protected function casts(): array
    {
        return ['basic_minor' => 'integer', 'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date'];
    }
}
