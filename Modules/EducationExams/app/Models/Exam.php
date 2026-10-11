<?php

namespace Modules\EducationExams\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An exam of a session at a campus: draft -> marking -> closed -> published.
 */
#[Fillable(['organization_id', 'unit_id', 'session_id', 'name', 'kind', 'starts_on', 'ends_on', 'grading_scheme_id', 'status', 'created_by', 'version'])]
class Exam extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const KINDS = ['term', 'final', 'test', 'assignment', 'other'];

    public const STATUSES = ['draft', 'marking', 'closed', 'published'];

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $table = 'exm_exams';

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'version' => 'integer'];
    }
}
