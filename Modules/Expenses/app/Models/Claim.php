<?php

namespace Modules\Expenses\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee's expense claim: a draft until submitted, then approved step by
 * step, then paid back (cash, bank, or with a month's salary). Approved and
 * paid claims are kept as they are.
 */
#[Fillable([
    'organization_id', 'unit_id', 'employee_id', 'number', 'title', 'currency', 'total_minor', 'status', 'steps_needed', 'steps_done',
    'submitted_at', 'decided_at', 'decided_by', 'decision_note', 'journal_id', 'pay_method', 'pay_period', 'pay_run_id',
    'paid_at', 'paid_by', 'payment_journal_id', 'requested_by', 'op_id', 'version',
])]
class Claim extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PAID = 'paid';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::APPROVED, self::REJECTED, self::PAID, self::WITHDRAWN];

    /** Paid back in cash or by bank now, or with a month's salary. */
    public const PAY_METHODS = ['cash', 'bank', 'payroll'];

    protected $table = 'exp_claims';

    protected function casts(): array
    {
        return [
            'total_minor' => 'integer', 'steps_needed' => 'integer', 'steps_done' => 'integer', 'version' => 'integer',
            'submitted_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime',
        ];
    }
}
