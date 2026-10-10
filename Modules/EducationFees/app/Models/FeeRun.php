<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Billing a month, a session or other heads for a campus, class or section: draft -> final (or cancelled).
 */
#[Fillable(['organization_id', 'unit_id', 'session_id', 'kind', 'period', 'level_id', 'section_id', 'head_ids', 'issue_date', 'due_date', 'status', 'bills_count', 'total_minor', 'note', 'created_by', 'finalized_by', 'finalized_at', 'op_id', 'version'])]
class FeeRun extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['monthly', 'session', 'other'];

    protected $table = 'fee_runs';

    protected function casts(): array
    {
        return ['head_ids' => 'array', 'issue_date' => 'immutable_date', 'due_date' => 'immutable_date', 'bills_count' => 'integer', 'total_minor' => 'integer', 'finalized_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
