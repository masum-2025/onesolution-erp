<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A month's payroll of a company:
 *
 *   draft (calculated, adjusted, calculated again) -> submit -> pending_approval
 *     -> approve (each level a different person) -> approved (posted to the books) -> paid
 *     -> reject -> draft
 *
 * Once approved its slips never change; a mistake is put right next month.
 */
#[Fillable(['organization_id', 'period', 'period_from', 'period_to', 'status', 'currency_code', 'created_by', 'version'])]
class Run extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const PENDING = 'pending_approval';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    protected $table = 'pay_runs';

    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'employees' => 'integer',
            'earnings_minor' => 'integer',
            'deductions_minor' => 'integer',
            'tax_minor' => 'integer',
            'net_minor' => 'integer',
            'calculated_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'paid_on' => 'immutable_date',
            'version' => 'integer',
        ];
    }
}
