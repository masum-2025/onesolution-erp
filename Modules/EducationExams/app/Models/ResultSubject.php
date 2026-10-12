<?php

namespace Modules\EducationExams\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A student's result in one subject of a result.
 */
#[Fillable(['organization_id', 'result_id', 'subject_id', 'paper_id', 'kind', 'marks_centi', 'full_marks_centi', 'percent_bp', 'letter', 'point_centi', 'credits_centi', 'passed', 'absent'])]
class ResultSubject extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'exm_result_subjects';

    protected function casts(): array
    {
        return ['marks_centi' => 'integer', 'full_marks_centi' => 'integer', 'percent_bp' => 'integer', 'point_centi' => 'integer', 'credits_centi' => 'integer', 'passed' => 'boolean', 'absent' => 'boolean'];
    }
}
