<?php

namespace Modules\EducationExams\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A final result made of several exams of a session for a class, each with its weight.
 */
#[Fillable(['organization_id', 'unit_id', 'session_id', 'level_id', 'name', 'exams', 'grading_scheme_id', 'status', 'created_by', 'version'])]
class Combination extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $table = 'exm_combinations';

    protected function casts(): array
    {
        return ['exams' => 'array', 'version' => 'integer'];
    }
}
