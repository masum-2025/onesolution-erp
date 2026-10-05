<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One movement of an employee's provident fund, never changed: a month's
 * contributions (positive), or what a final settlement paid out or kept
 * back (negative). The balance is the sum.
 */
#[Fillable(['organization_id', 'employee_id', 'unit_id', 'kind', 'period', 'employee_minor', 'employer_minor', 'currency_code', 'run_id', 'settlement_id'])]
class PfEntry extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const CONTRIBUTION = 'contribution';

    public const WITHDRAWAL = 'withdrawal';

    public const FORFEIT = 'forfeit';

    public const UPDATED_AT = null;

    protected $table = 'pay_pf_entries';

    protected function casts(): array
    {
        return ['employee_minor' => 'integer', 'employer_minor' => 'integer'];
    }
}
