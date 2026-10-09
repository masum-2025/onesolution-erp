<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * How a guardian relates to a student, and what they may do.
 */
#[Fillable(['organization_id', 'student_id', 'guardian_id', 'relation', 'is_primary', 'can_pick_up', 'receives_notices'])]
class StudentGuardian extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'edu_student_guardians';

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'can_pick_up' => 'boolean',
            'receives_notices' => 'boolean',
        ];
    }
}
