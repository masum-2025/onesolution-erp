<?php

namespace Modules\EducationExams\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One student's marks in one part of a paper (hundredths), or absent.
 */
#[Fillable(['organization_id', 'sheet_id', 'paper_id', 'part_id', 'student_id', 'marks_centi', 'absent', 'entered_by'])]
class Mark extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'exm_marks';

    protected function casts(): array
    {
        return ['marks_centi' => 'integer', 'absent' => 'boolean'];
    }
}
