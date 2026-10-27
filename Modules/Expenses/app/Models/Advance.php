<?php

namespace Modules\Expenses\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Money an employee asks for before spending it (a trip, a purchase):
 * approved step by step like a claim, paid in cash or by bank, then settled
 * by their claims, by handing back what is left, or from a month's salary.
 */
#[Fillable([
    'organization_id', 'unit_id', 'employee_id', 'number', 'purpose', 'currency', 'amount_minor', 'settle_by', 'status', 'steps_needed', 'steps_done',
    'requested_by', 'decided_at', 'decided_by', 'decision_note', 'pay_method', 'paid_at', 'paid_by', 'journal_id', 'recover_period', 'settled_at', 'op_id', 'version',
])]
class Advance extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PAID = 'paid';

    public const SETTLED = 'settled';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::SUBMITTED, self::APPROVED, self::REJECTED, self::PAID, self::SETTLED, self::WITHDRAWN];

    /** Still owed by the employee or about to be: counted against expenses.advance_max_open. */
    public const OPEN = [self::SUBMITTED, self::APPROVED, self::PAID];

    protected $table = 'exp_advances';

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer', 'steps_needed' => 'integer', 'steps_done' => 'integer', 'version' => 'integer',
            'settle_by' => 'immutable_date', 'decided_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime',
        ];
    }
}
