<?php

namespace Modules\EducationExams\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * How percentages become letters and grade points; name in each language.
 */
#[Fillable(['organization_id', 'code', 'name', 'max_point_centi', 'is_active', 'version'])]
class GradingScheme extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $table = 'exm_grading_schemes';

    protected function casts(): array
    {
        return ['max_point_centi' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }
}
