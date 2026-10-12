<?php

namespace Modules\EducationExams\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Changing a mark after results are published: asked with a reason, applied when approved.
 */
#[Fillable(['organization_id', 'unit_id', 'exam_id', 'sheet_id', 'part_id', 'student_id', 'old_marks_centi', 'old_absent', 'new_marks_centi', 'new_absent', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'version'])]
class Correction extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'exm_corrections';

    protected function casts(): array
    {
        return ['old_marks_centi' => 'integer', 'old_absent' => 'boolean', 'new_marks_centi' => 'integer', 'new_absent' => 'boolean', 'decided_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
