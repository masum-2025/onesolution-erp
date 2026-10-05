<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Money lent to an employee and recovered from their salary:
 *
 *   pending_approval -> approve (another person; paid out, posted) -> active
 *     -> recovered month by month by approved payroll runs -> closed
 *   pending_approval -> reject | cancel
 *
 * Each month takes one instalment (the last one the rest); a month can be
 * held back with a reason. The amounts never change once it is active.
 */
#[Fillable(['organization_id', 'employee_id', 'unit_id', 'employee_code', 'employee_name', 'kind', 'principal_minor', 'installments', 'installment_minor',
    'start_period', 'paid_out_on', 'reason', 'status', 'currency_code', 'created_by', 'version'])]
class Loan extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['loan', 'advance'];

    public const PENDING = 'pending_approval';

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $table = 'pay_loans';

    protected function casts(): array
    {
        return [
            'principal_minor' => 'integer',
            'installments' => 'integer',
            'installment_minor' => 'integer',
            'recovered_minor' => 'integer',
            'paid_out_on' => 'immutable_date',
            'decided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function balance(): int
    {
        return $this->principal_minor - $this->recovered_minor;
    }
}
