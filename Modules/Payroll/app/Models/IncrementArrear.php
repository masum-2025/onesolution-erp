<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One employee's increment arrears for one month already paid: planned when
 * the round is applied, taken into the next run, paid when it is approved.
 */
#[Fillable(['organization_id', 'round_id', 'line_id', 'employee_id', 'unit_id', 'period', 'slip_id', 'previous_minor', 'new_minor', 'amount_minor',
    'taxable_minor', 'pf_base_minor', 'currency_code', 'status', 'run_id'])]
class IncrementArrear extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PLANNED = 'planned';

    public const PAID = 'paid';

    protected $table = 'pay_increment_arrears';

    protected function casts(): array
    {
        return [
            'previous_minor' => 'integer', 'new_minor' => 'integer', 'amount_minor' => 'integer', 'taxable_minor' => 'integer', 'pf_base_minor' => 'integer',
        ];
    }
}
