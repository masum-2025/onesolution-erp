<?php

namespace Modules\EducationExams\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A paper for one section: who enters it, and open -> submitted -> approved (or returned).
 */
#[Fillable(['organization_id', 'unit_id', 'exam_id', 'paper_id', 'section_id', 'teacher_id', 'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'note', 'version'])]
class MarkSheet extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['open', 'submitted', 'approved', 'returned'];

    protected $table = 'exm_mark_sheets';

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
