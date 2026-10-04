<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One employee's pay in a run: the basic and days it was worked out from,
 * earnings, deductions, tax and net (lines in pay_slip_lines). Rewritten
 * while the run is a draft; frozen once it is approved.
 */
#[Fillable([
    'organization_id', 'run_id', 'unit_id', 'employee_id', 'employee_code', 'employee_name', 'salary_id', 'basic_minor',
    'period_days', 'employed_days', 'absent_days', 'half_days', 'overtime_minutes', 'late_minutes',
    'earnings_minor', 'deductions_minor', 'tax_minor', 'net_minor', 'problem',
])]
class Slip extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pay_slips';

    protected function casts(): array
    {
        return [
            'basic_minor' => 'integer', 'period_days' => 'integer', 'employed_days' => 'integer', 'absent_days' => 'integer',
            'half_days' => 'integer', 'overtime_minutes' => 'integer', 'late_minutes' => 'integer',
            'earnings_minor' => 'integer', 'deductions_minor' => 'integer', 'tax_minor' => 'integer', 'net_minor' => 'integer',
        ];
    }
}
