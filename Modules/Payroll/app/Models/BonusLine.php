<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One employee in a festival bonus: paid or why not, the bonus, tax at source, net. */
#[Fillable(['organization_id', 'bonus_run_id', 'employee_id', 'unit_id', 'employee_code', 'employee_name', 'basic_minor', 'service_months',
    'not_paid_reason', 'override_minor', 'excluded', 'gross_minor', 'tax_minor', 'net_minor'])]
class BonusLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pay_bonus_lines';

    protected function casts(): array
    {
        return [
            'basic_minor' => 'integer',
            'service_months' => 'integer',
            'override_minor' => 'integer',
            'excluded' => 'boolean',
            'gross_minor' => 'integer',
            'tax_minor' => 'integer',
            'net_minor' => 'integer',
        ];
    }
}
