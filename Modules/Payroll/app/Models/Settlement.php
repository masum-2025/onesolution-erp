<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The final settlement of someone who left, one per employee:
 *
 *   draft (calculated, lines added, calculated again) -> submit -> pending_approval
 *     -> approve (another person; posted, loans closed, provident fund paid out) -> approved -> paid
 *     -> reject -> draft
 */
#[Fillable(['organization_id', 'employee_id', 'unit_id', 'employee_code', 'employee_name', 'joined_on', 'left_on', 'status', 'currency_code', 'created_by', 'version'])]
class Settlement extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const PENDING = 'pending_approval';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    protected $table = 'pay_settlements';

    protected function casts(): array
    {
        return [
            'joined_on' => 'immutable_date',
            'left_on' => 'immutable_date',
            'service_years' => 'integer',
            'service_months' => 'integer',
            'basic_minor' => 'integer',
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
