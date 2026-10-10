<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A student's bill: never deleted; open, paid or cancelled with a reason.
 */
#[Fillable(['organization_id', 'unit_id', 'student_id', 'session_id', 'run_id', 'billing_key', 'active_key', 'number', 'issue_date', 'due_date', 'status', 'currency', 'gross_minor', 'discount_minor', 'tax_minor', 'fine_minor', 'total_minor', 'paid_minor', 'fines_stopped_at', 'source', 'cancel_reason', 'journal_id', 'created_by', 'version'])]
class Bill extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['draft', 'open', 'paid', 'cancelled'];

    /** What is still owed. */
    public function balanceMinor(): int
    {
        return max(0, $this->total_minor - $this->paid_minor);
    }

    protected $table = 'fee_bills';

    protected function casts(): array
    {
        return ['issue_date' => 'immutable_date', 'due_date' => 'immutable_date', 'gross_minor' => 'integer', 'discount_minor' => 'integer', 'tax_minor' => 'integer', 'fine_minor' => 'integer', 'total_minor' => 'integer', 'paid_minor' => 'integer', 'fines_stopped_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
