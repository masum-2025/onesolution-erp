<?php

namespace Modules\EducationExams\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A part of a paper (creative, MCQ, practical…) with its own full and pass marks.
 */
#[Fillable(['organization_id', 'paper_id', 'code', 'name', 'full_marks_centi', 'pass_marks_centi', 'sort_order'])]
class PaperPart extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $table = 'exm_paper_parts';

    protected function casts(): array
    {
        return ['full_marks_centi' => 'integer', 'pass_marks_centi' => 'integer', 'sort_order' => 'integer'];
    }
}
