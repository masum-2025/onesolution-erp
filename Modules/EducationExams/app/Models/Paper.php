<?php

namespace Modules\EducationExams\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A subject's paper for a class in an exam: full and pass marks (hundredths) and its routine.
 */
#[Fillable(['organization_id', 'exam_id', 'level_id', 'subject_id', 'kind', 'full_marks_centi', 'pass_marks_centi', 'sits_on', 'starts_at', 'minutes', 'room', 'version'])]
class Paper extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'exm_papers';

    protected function casts(): array
    {
        return ['full_marks_centi' => 'integer', 'pass_marks_centi' => 'integer', 'sits_on' => 'immutable_date', 'minutes' => 'integer', 'version' => 'integer'];
    }
}
