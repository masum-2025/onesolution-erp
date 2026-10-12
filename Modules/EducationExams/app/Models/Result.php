<?php

namespace Modules\EducationExams\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One student's result of an exam or a combination: totals, GPA, letter, pass, position.
 */
#[Fillable(['organization_id', 'source_type', 'source_id', 'unit_id', 'session_id', 'level_id', 'section_id', 'student_id', 'marks_centi', 'full_marks_centi', 'percent_bp', 'gpa_centi', 'letter', 'passed', 'failed_subjects', 'credits_centi', 'rank', 'section_rank', 'status', 'published_at'])]
class Result extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'exm_results';

    protected function casts(): array
    {
        return ['marks_centi' => 'integer', 'full_marks_centi' => 'integer', 'percent_bp' => 'integer', 'gpa_centi' => 'integer', 'passed' => 'boolean', 'failed_subjects' => 'integer', 'credits_centi' => 'integer', 'rank' => 'integer', 'section_rank' => 'integer', 'published_at' => 'immutable_datetime'];
    }
}
