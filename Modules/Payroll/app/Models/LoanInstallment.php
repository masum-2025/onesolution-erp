<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** What one month does with a loan: planned in a draft payroll run, recovered when it is approved, or held back with a reason. */
#[Fillable(['organization_id', 'loan_id', 'period', 'status', 'amount_minor', 'run_id', 'reason', 'created_by'])]
class LoanInstallment extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PLANNED = 'planned';

    public const RECOVERED = 'recovered';

    public const SKIPPED = 'skipped';

    protected $table = 'pay_loan_installments';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
