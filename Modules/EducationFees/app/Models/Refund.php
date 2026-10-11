<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Paying a student's advance back: asked, then decided by another person.
 */
#[Fillable(['organization_id', 'unit_id', 'student_id', 'amount_minor', 'currency', 'method', 'reference', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'journal_id', 'version'])]
class Refund extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fee_refunds';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'decided_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
