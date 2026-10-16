<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Who teaches a subject in a section (an HRM employee).
 */
#[Fillable(['organization_id', 'section_id', 'subject_id', 'teacher_id', 'version'])]
class SubjectTeacher extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'edu_subject_teachers';

    /** A new row answers with its version (the screens send it back to change it). */
    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
